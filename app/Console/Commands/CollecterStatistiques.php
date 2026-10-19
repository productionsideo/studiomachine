<?php

namespace App\Console\Commands;

use App\Models\Integration;
use App\Models\PostTarget;
use App\Services\Statistiques\Collecteur;
use App\Services\Statistiques\CollecteurFacebook;
use App\Services\Statistiques\CollecteurInstagram;
use App\Services\Statistiques\CollecteurYoutube;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Collecte les statistiques des comptes connectés pour le tableau de bord
 * Résumé : les chiffres du compte jour par jour (stats_comptes) et ceux de
 * chaque publication (stats_publications).
 *
 * Première collecte d'un compte : on remonte aussi loin que le réseau le
 * permet (30 jours pour le compte, 90 pour les publications). Ensuite : les
 * 3 derniers jours, que les réseaux corrigent encore, et les publications
 * des 30 derniers jours, dont les chiffres bougent.
 *
 * Une valeur absente n'efface jamais une valeur connue, et un compte en
 * erreur n'empêche pas les autres.
 */
class CollecterStatistiques extends Command
{
    protected $signature = 'statistiques:collecter {--client= : Seulement ce client (slug)} {--integration= : Seulement ce compte (id)}';

    protected $description = 'Collecte les statistiques des comptes de réseaux sociaux pour le tableau de bord';

    /** Le réseau → son collecteur. TikTok et LinkedIn s'ajouteront avec leurs accès. */
    public const COLLECTEURS = [
        'facebook'  => CollecteurFacebook::class,
        'instagram' => CollecteurInstagram::class,
        'youtube'   => CollecteurYoutube::class,
    ];

    public function handle(): int
    {
        $integrations = Integration::query()
            ->where('active', true)
            ->whereIn('platform', array_keys(self::COLLECTEURS))
            ->when($this->option('client'), fn ($q, $slug) => $q->whereHas('client', fn ($c) => $c->where('slug', $slug)))
            ->when($this->option('integration'), fn ($q, $id) => $q->whereKey($id))
            ->with('client')
            ->get();

        foreach ($integrations as $integration) {
            $etiquette = "{$integration->client->name} · {$integration->platform}";

            try {
                [$jours, $pubs] = $this->collecter($integration, app(self::COLLECTEURS[$integration->platform]));
                $this->info("✓ {$etiquette} : {$jours} jour(s), {$pubs} publication(s)");

                if (str_starts_with((string) $integration->last_error, 'Statistiques :')) {
                    $integration->update(['last_error' => null]);
                }
            } catch (\Throwable $e) {
                $this->error("✗ {$etiquette} : {$e->getMessage()}");
                $integration->update(['last_error' => mb_substr('Statistiques : ' . $e->getMessage(), 0, 500)]);
            }
        }

        return self::SUCCESS;
    }

    private function collecter(Integration $integration, Collecteur $collecteur): array
    {
        $premiere = ! DB::table('stats_comptes')->where('integration_id', $integration->id)->exists();

        $jours = $collecteur->jours($integration, now()->subDays($premiere ? 30 : 3));
        $pubs  = $collecteur->publications($integration, now()->subDays($premiere ? 90 : 30));

        DB::transaction(function () use ($integration, $jours, $pubs) {
            foreach ($jours as $jour => $valeurs) {
                $valeurs = array_filter($valeurs, fn ($v) => $v !== null);
                if (! $valeurs) {
                    continue;
                }

                $existe = DB::table('stats_comptes')->where('integration_id', $integration->id)->where('jour', $jour);
                if ($existe->exists()) {
                    $existe->update($valeurs + ['updated_at' => now()]);
                } else {
                    DB::table('stats_comptes')->insert($valeurs + [
                        'integration_id' => $integration->id, 'client_id' => $integration->client_id,
                        'platform' => $integration->platform, 'jour' => $jour,
                        'created_at' => now(), 'updated_at' => now(),
                    ]);
                }
            }

            // Nos propres publications, pour relier les chiffres à la cible.
            $cibles = PostTarget::where('integration_id', $integration->id)
                ->whereNotNull('external_post_id')
                ->pluck('id', 'external_post_id');

            foreach ($pubs as $p) {
                $valeurs = [
                    'client_id'       => $integration->client_id,
                    'post_target_id'  => $cibles[$p['external_id']] ?? null,
                    'platform'        => $integration->platform,
                    'type'            => $p['type'],
                    'publie_le'       => $p['publie_le']->copy()->utc(),
                    'texte'           => $p['texte'] !== null ? mb_substr($p['texte'], 0, 5000) : null,
                    'vignette'        => $p['vignette'] !== null ? mb_substr($p['vignette'], 0, 1000) : null,
                    'lien'            => $p['lien'] !== null ? mb_substr($p['lien'], 0, 1000) : null,
                    'vues'            => $p['vues'],
                    'portee'          => $p['portee'],
                    'interactions'    => $p['interactions'],
                    'j_aime'          => $p['j_aime'],
                    'commentaires'    => $p['commentaires'],
                    'partages'        => $p['partages'],
                    'enregistrements' => $p['enregistrements'],
                    'collecte_le'     => now(),
                    'updated_at'      => now(),
                ];

                $existe = DB::table('stats_publications')->where('integration_id', $integration->id)->where('external_id', $p['external_id']);

                // created_at = première fois qu'on l'a vue : il dit jusqu'où remonte la collecte.
                $existe->exists()
                    ? $existe->update($valeurs)
                    : DB::table('stats_publications')->insert($valeurs + ['integration_id' => $integration->id, 'external_id' => $p['external_id'], 'created_at' => now()]);
            }
        });

        return [count($jours), count($pubs)];
    }
}
