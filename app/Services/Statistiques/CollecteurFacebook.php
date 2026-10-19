<?php

namespace App\Services\Statistiques;

use App\Models\Integration;
use App\Services\Publication\ClientGraph;
use App\Services\Publication\ErreurReseau;
use Illuminate\Support\Carbon;

/**
 * Statistiques d'une Page Facebook (API Graph, jeton de Page).
 *
 * Indicateurs retenus (documentation v26, octobre 2026) : `page_media_view`
 * pour les vues — les « impressions » sont retirées depuis la v25 —,
 * `page_post_engagements` pour les interactions, `page_follows` pour
 * l'historique des abonnés. Meta en retire d'autres au fil de 2026 : chaque
 * indicateur est demandé seul, et celui qui manque reste vide sans emporter
 * les autres.
 */
class CollecteurFacebook implements Collecteur
{
    public function __construct(protected ClientGraph $graphe) {}

    public function jours(Integration $integration, Carbon $depuis): array
    {
        $page  = $this->page($integration);
        $jeton = $integration->access_token;
        $jours = [];

        foreach (['vues' => 'page_media_view', 'interactions' => 'page_post_engagements', 'abonnes' => 'page_follows'] as $champ => $metrique) {
            try {
                $r = $this->graphe->appeler("/{$page}/insights", $jeton, [
                    'metric' => $metrique,
                    'period' => 'day',
                    'since'  => $depuis->copy()->startOfDay()->timestamp,
                    'until'  => now()->timestamp,
                ]);
            } catch (ErreurReseau $e) {
                if ($e->getCode() === 190) {
                    throw $e;       // jeton refusé : tout le compte est illisible
                }
                continue;           // indicateur retiré ou non disponible : on passe
            }

            foreach ($r['data'][0]['values'] ?? [] as $v) {
                // Meta date la valeur à la FIN du jour (minuit, heure du Pacifique).
                $jour = Carbon::parse($v['end_time'])->setTimezone('America/Los_Angeles')->subDay()->toDateString();
                $jours[$jour][$champ] = is_numeric($v['value'] ?? null) ? (int) $v['value'] : null;
            }
        }

        // Le total d'aujourd'hui, toujours fiable, prime sur l'historique.
        $fiche = $this->graphe->appeler("/{$page}", $jeton, ['fields' => 'followers_count,fan_count']);
        $jours[now(config('publication.fuseau'))->toDateString()]['abonnes'] = (int) ($fiche['followers_count'] ?? $fiche['fan_count'] ?? 0);

        return $jours;
    }

    public function publications(Integration $integration, Carbon $depuis): array
    {
        $page  = $this->page($integration);
        $jeton = $integration->access_token;

        $champs = 'id,created_time,message,permalink_url,full_picture,status_type,attachments{media_type},'
            . 'shares,comments.summary(true).limit(0),reactions.summary(true).limit(0)';

        $liste = $this->tout("/{$page}/published_posts", $jeton, [
            'fields' => $champs,
            'since'  => $depuis->timestamp,
            'limit'  => 50,
        ], 300);

        return array_map(function ($p) use ($jeton) {
            $jaime = (int) ($p['reactions']['summary']['total_count'] ?? 0);
            $comm  = (int) ($p['comments']['summary']['total_count'] ?? 0);
            $part  = (int) ($p['shares']['count'] ?? 0);

            return [
                'external_id'     => $p['id'],
                'type'            => $this->type($p),
                'publie_le'       => Carbon::parse($p['created_time']),
                'texte'           => $p['message'] ?? null,
                'vignette'        => $p['full_picture'] ?? null,
                'lien'            => $p['permalink_url'] ?? null,
                'vues'            => $this->vues($p['id'], $jeton),
                'portee'          => null,
                'interactions'    => $jaime + $comm + $part,
                'j_aime'          => $jaime,
                'commentaires'    => $comm,
                'partages'        => $part,
                'enregistrements' => null,
            ];
        }, $liste);
    }

    // ---------------------------------------------------------------------

    private function page(Integration $integration): string
    {
        return $integration->settings['page_id'] ?? $integration->external_account_id;
    }

    /** Les vues d'une publication (post_media_view), ou null si Meta ne les donne pas. */
    private function vues(string $id, string $jeton): ?int
    {
        try {
            $r = $this->graphe->appeler("/{$id}/insights", $jeton, ['metric' => 'post_media_view']);
            $v = $r['data'][0]['values'][0]['value'] ?? null;

            return is_numeric($v) ? (int) $v : null;
        } catch (ErreurReseau) {
            return null;
        }
    }

    private function type(array $p): string
    {
        $media = $p['attachments']['data'][0]['media_type'] ?? null;

        return match (true) {
            $media === 'video' || str_contains((string) ($p['status_type'] ?? ''), 'video') => 'video',
            $media === 'album'  => 'carrousel',
            $media === 'photo'  => 'image',
            default             => 'texte',
        };
    }

    /** Suit la pagination de Meta jusqu'à $max éléments. */
    protected function tout(string $chemin, string $jeton, array $params, int $max): array
    {
        $elements = [];
        $r = $this->graphe->appeler($chemin, $jeton, $params);

        while (true) {
            array_push($elements, ...($r['data'] ?? []));
            $suivant = $r['paging']['cursors']['after'] ?? null;

            if (! $suivant || empty($r['paging']['next']) || count($elements) >= $max) {
                break;
            }
            $r = $this->graphe->appeler($chemin, $jeton, $params + ['after' => $suivant]);
        }

        return array_slice($elements, 0, $max);
    }
}
