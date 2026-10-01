<?php

namespace App\Console\Commands;

use App\Models\Campaign;
use App\Models\Client;
use App\Models\Integration;
use App\Models\MediaAsset;
use App\Services\Mediatheque;
use App\Services\Publication\Reseaux;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Importe un plan de campagne (fichier JSON) : la campagne, ses médias et
 * ses publications, en BROUILLON.
 *
 * Rien n'est programmé ici. Une publication ne part qu'après avoir été
 * ouverte et validée dans l'éditeur, où les règles de chaque réseau sont
 * vérifiées — c'est aussi là qu'on constate qu'un compte n'est pas branché.
 *
 * Relançable sans doublon : la campagne est retrouvée par son code de suivi,
 * une publication par son libellé, un média par son empreinte.
 *
 * Format : voir deploy/campagnes/exemple.json.
 */
class ImporterCampagne extends Command
{
    protected $signature = 'campagne:importer
                            {fichier : Chemin du plan JSON ; les médias sont cherchés à côté}
                            {--client= : Remplace le client du plan (son slug)}
                            {--essai : Montre ce qui serait fait, sans rien écrire}';

    protected $description = 'Importe une campagne et ses publications en brouillon à partir d’un plan JSON';

    public function handle(Mediatheque $mediatheque): int
    {
        $fichier = $this->argument('fichier');
        $plan    = json_decode((string) @file_get_contents($fichier), true);

        if (! is_array($plan)) {
            $this->error("Plan illisible : {$fichier}");

            return self::FAILURE;
        }

        $dossier = dirname(realpath($fichier));
        $client  = Client::where('slug', $this->option('client') ?: ($plan['client'] ?? ''))->first();

        if (! $client) {
            $this->error('Client introuvable : ' . ($this->option('client') ?: ($plan['client'] ?? '(aucun)')));

            return self::FAILURE;
        }

        // Tout est vérifié avant d'écrire quoi que ce soit : un plan à moitié
        // importé est pire qu'un plan refusé.
        $problemes = $this->verifier($plan, $dossier);
        if ($problemes) {
            foreach ($problemes as $p) {
                $this->error("  ✗ {$p}");
            }

            return self::FAILURE;
        }

        $comptes = $client->integrations()->where('active', true)->get()->keyBy('platform');
        $absents = collect($plan['publications'])->flatMap(fn ($p) => array_keys($p['reseaux']))->unique()
            ->reject(fn ($r) => $comptes->has($r))->values();

        $this->info("Client : {$client->name}");
        $this->line('Campagne : ' . $plan['campagne']['name'] . ' (' . $plan['campagne']['utm_campaign'] . ')');
        $this->line(count($plan['publications']) . ' publication(s), en brouillon.');
        if ($absents->isNotEmpty()) {
            $this->warn('Comptes pas encore connectés : ' . $absents->map(fn ($r) => Reseaux::nom($r))->implode(', ')
                . ' — les publications seront prêtes, à programmer une fois connectés.');
        }

        if ($this->option('essai')) {
            foreach ($plan['publications'] as $p) {
                $this->line("  · {$p['jour']} {$p['heure']}  {$p['title']}  [" . implode(', ', array_keys($p['reseaux'])) . ']');
            }
            $this->info('Essai seulement : rien n’a été écrit.');

            return self::SUCCESS;
        }

        DB::transaction(function () use ($plan, $client, $dossier, $mediatheque, $comptes) {
            $c = $plan['campagne'];

            $campagne = Campaign::firstOrNew(['client_id' => $client->id, 'utm_campaign' => $c['utm_campaign']]);
            $campagne->fill([
                'name'      => $c['name'],
                'objective' => $c['objective'] ?? null,
                'color'     => $c['color'] ?? '#9A0F20',
                'starts_on' => $c['starts_on'] ?? null,
                'ends_on'   => $c['ends_on'] ?? null,
            ])->save();

            foreach ($plan['publications'] as $p) {
                $post = $campagne->posts()->firstOrNew(['title' => $p['title']]);

                if ($post->exists && ! $post->estModifiable()) {
                    $this->line("  = {$p['title']} : déjà partie, laissée telle quelle");
                    continue;
                }

                $post->fill([
                    'client_id'    => $client->id,
                    'caption'      => $p['caption'],
                    'link_url'     => $p['link_url'] ?? null,
                    'scheduled_at' => Carbon::parse("{$p['jour']} {$p['heure']}", config('publication.fuseau'))->utc(),
                    'status'       => 'brouillon',
                ])->save();

                $ids = [];
                foreach ($p['medias'] ?? [] as $i => $chemin) {
                    $ids[$this->media($client, $dossier . '/' . $chemin, $mediatheque)] = ['position' => $i];
                }
                $post->media()->sync($ids);

                $post->targets()->whereNotIn('platform', array_keys($p['reseaux']))->delete();
                foreach ($p['reseaux'] as $reseau => $r) {
                    $post->targets()->updateOrCreate(['platform' => $reseau], [
                        'integration_id'   => $comptes[$reseau]->id ?? null,
                        'format'           => $r['format'],
                        'caption_override' => $r['texte'] ?? null,
                        'options'          => $r['options'] ?? [],
                        'status'           => 'en_attente',
                    ]);
                }

                $this->line("  ✓ {$p['jour']} {$p['heure']}  {$p['title']}");
            }
        });

        $this->info('Importé. Ouvrez chaque publication dans le calendrier pour la programmer.');

        return self::SUCCESS;
    }

    private function verifier(array $plan, string $dossier): array
    {
        $p = [];

        foreach (['name', 'utm_campaign'] as $cle) {
            if (empty($plan['campagne'][$cle])) {
                $p[] = "campagne.{$cle} manquant.";
            }
        }
        if (! preg_match('/^[a-z0-9_-]+$/', $plan['campagne']['utm_campaign'] ?? '')) {
            $p[] = 'campagne.utm_campaign : minuscules, chiffres, - et _ seulement.';
        }

        foreach ($plan['publications'] ?? [] as $i => $pub) {
            $n = $pub['title'] ?? "publication {$i}";

            if (empty($pub['title'])) {
                $p[] = "Publication {$i} : libellé (title) manquant — il sert à ne pas l’importer deux fois.";
            }
            if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $pub['jour'] ?? '') || ! preg_match('/^\d{2}:\d{2}$/', $pub['heure'] ?? '')) {
                $p[] = "{$n} : jour (AAAA-MM-JJ) et heure (HH:MM) requis.";
            }
            foreach ($pub['medias'] ?? [] as $m) {
                if (! is_file("{$dossier}/{$m}")) {
                    $p[] = "{$n} : média introuvable ({$m}).";
                }
            }
            foreach ($pub['reseaux'] ?? [] as $reseau => $r) {
                if (! isset(Reseaux::CATALOGUE[$reseau]['formats'][$r['format'] ?? ''])) {
                    $p[] = "{$n} : format inconnu pour {$reseau} (" . ($r['format'] ?? '?') . ').';
                }
            }
            if (empty($pub['reseaux'])) {
                $p[] = "{$n} : aucun réseau.";
            }
        }

        return $p;
    }

    /** Range un fichier dans la médiathèque, sauf s'il y est déjà. */
    private function media(Client $client, string $chemin, Mediatheque $mediatheque): int
    {
        $empreinte = hash_file('sha256', $chemin);

        $existant = MediaAsset::where('client_id', $client->id)
            ->where('original_name', basename($chemin))
            ->get()
            ->first(fn ($m) => is_file($m->chemin()) && $this->memeFichier($m, $chemin, $empreinte));

        if ($existant) {
            return $existant->id;
        }

        // ranger() déplace le fichier : on lui en donne une copie.
        $copie = config('publication.medias.temporaire') . '/import-' . bin2hex(random_bytes(8));
        @mkdir(dirname($copie), 0775, true);
        copy($chemin, $copie);

        return $mediatheque->ranger($client, null, $copie, basename($chemin))->id;
    }

    private function memeFichier(MediaAsset $m, string $chemin, string $empreinte): bool
    {
        // Une image PNG est convertie en JPEG à l'import : son empreinte
        // change. On se contente alors du nom et de la taille d'origine.
        return $m->mime === 'image/jpeg' && ! str_ends_with(strtolower($chemin), '.jpg') && ! str_ends_with(strtolower($chemin), '.jpeg')
            ? true
            : hash_file('sha256', $m->chemin()) === $empreinte;
    }
}
