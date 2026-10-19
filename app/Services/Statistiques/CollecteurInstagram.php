<?php

namespace App\Services\Statistiques;

use App\Models\Integration;
use App\Services\Publication\ClientGraph;
use App\Services\Publication\ErreurReseau;
use Illuminate\Support\Carbon;

/**
 * Statistiques d'un compte Instagram professionnel (API Graph, jeton de Page).
 *
 * `views` remplace `impressions`, retiré en 2025. Les indicateurs du compte
 * (views, reach, total_interactions) ne se lisent qu'en total sur une période
 * (metric_type=total_value) : on les demande donc jour par jour, 31 jours au
 * plus en arrière.
 */
class CollecteurInstagram implements Collecteur
{
    /** Au-delà, une collecte devient trop longue : 30 appels pour remplir un mois. */
    private const JOURS_MAX = 31;

    public function __construct(private ClientGraph $graphe) {}

    public function jours(Integration $integration, Carbon $depuis): array
    {
        $ig     = $this->compte($integration);
        $jeton  = $integration->access_token;
        $fuseau = config('publication.fuseau');
        $jours  = [];

        $debut = $depuis->copy()->setTimezone($fuseau)->startOfDay()->max(now($fuseau)->subDays(self::JOURS_MAX)->startOfDay());

        for ($j = $debut->copy(); $j->lte(now($fuseau)); $j->addDay()) {
            try {
                $r = $this->graphe->appeler("/{$ig}/insights", $jeton, [
                    'metric'      => 'views,reach,total_interactions',
                    'metric_type' => 'total_value',
                    'period'      => 'day',
                    'since'       => $j->copy()->startOfDay()->timestamp,
                    'until'       => $j->copy()->endOfDay()->min(now())->timestamp,
                ]);
            } catch (ErreurReseau $e) {
                if ($e->getCode() === 190) {
                    throw $e;
                }
                continue;
            }

            foreach ($r['data'] ?? [] as $m) {
                $champ = ['views' => 'vues', 'reach' => 'portee', 'total_interactions' => 'interactions'][$m['name']] ?? null;
                if ($champ) {
                    $jours[$j->toDateString()][$champ] = (int) ($m['total_value']['value'] ?? 0);
                }
            }
        }

        // Le total d'aujourd'hui seulement : `follower_count` ne compte que les
        // nouveaux abonnés (pas les départs), il fausserait une courbe
        // reconstruite. L'historique se bâtit au fil des collectes quotidiennes.
        $jours[now($fuseau)->toDateString()]['abonnes'] = (int) ($this->graphe->appeler("/{$ig}", $jeton, ['fields' => 'followers_count'])['followers_count'] ?? 0);

        return $jours;
    }

    public function publications(Integration $integration, Carbon $depuis): array
    {
        $ig    = $this->compte($integration);
        $jeton = $integration->access_token;

        $medias = [];
        $r = $this->graphe->appeler("/{$ig}/media", $jeton, [
            'fields' => 'id,caption,media_type,media_product_type,permalink,thumbnail_url,media_url,timestamp,like_count,comments_count',
            'limit'  => 50,
        ]);

        while (true) {
            foreach ($r['data'] ?? [] as $m) {
                if (Carbon::parse($m['timestamp'])->lt($depuis)) {
                    break 2;    // les médias arrivent du plus récent au plus ancien
                }
                $medias[] = $m;
            }
            $apres = $r['paging']['cursors']['after'] ?? null;
            if (! $apres || empty($r['paging']['next']) || count($medias) >= 200) {
                break;
            }
            $r = $this->graphe->appeler("/{$ig}/media", $jeton, ['fields' => 'id,caption,media_type,media_product_type,permalink,thumbnail_url,media_url,timestamp,like_count,comments_count', 'limit' => 50, 'after' => $apres]);
        }

        return array_map(function ($m) use ($jeton) {
            $story   = ($m['media_product_type'] ?? '') === 'STORY';
            $chiffres = $this->chiffres($m['id'], $jeton, $story);

            return [
                'external_id'     => $m['id'],
                'type'            => match (true) {
                    ($m['media_product_type'] ?? '') === 'REELS' => 'reel',
                    $story                                         => 'story',
                    $m['media_type'] === 'CAROUSEL_ALBUM'          => 'carrousel',
                    $m['media_type'] === 'VIDEO'                   => 'video',
                    default                                        => 'image',
                },
                'publie_le'       => Carbon::parse($m['timestamp']),
                'texte'           => $m['caption'] ?? null,
                'vignette'        => $m['thumbnail_url'] ?? $m['media_url'] ?? null,
                'lien'            => $m['permalink'] ?? null,
                'vues'            => $chiffres['views'] ?? null,
                'portee'          => $chiffres['reach'] ?? null,
                'interactions'    => $chiffres['total_interactions'] ?? null,
                'j_aime'          => isset($m['like_count']) ? (int) $m['like_count'] : null,
                'commentaires'    => isset($m['comments_count']) ? (int) $m['comments_count'] : null,
                'partages'        => $chiffres['shares'] ?? null,
                'enregistrements' => $chiffres['saved'] ?? null,
            ];
        }, $medias);
    }

    // ---------------------------------------------------------------------

    private function compte(Integration $integration): string
    {
        return $integration->settings['ig_user_id'] ?? $integration->external_account_id;
    }

    private function chiffres(string $media, string $jeton, bool $story): array
    {
        // Une story n'a ni « enregistrements » ni J'aime dans les statistiques.
        $metriques = $story ? 'views,reach,shares,total_interactions' : 'views,reach,shares,saved,total_interactions';

        try {
            $r = $this->graphe->appeler("/{$media}/insights", $jeton, ['metric' => $metriques]);
        } catch (ErreurReseau) {
            return [];
        }

        $chiffres = [];
        foreach ($r['data'] ?? [] as $m) {
            $chiffres[$m['name']] = (int) ($m['values'][0]['value'] ?? $m['total_value']['value'] ?? 0);
        }

        return $chiffres;
    }
}
