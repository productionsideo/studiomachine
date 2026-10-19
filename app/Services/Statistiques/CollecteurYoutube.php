<?php

namespace App\Services\Statistiques;

use App\Models\Integration;
use App\Services\Connexion\FournisseurGoogle;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

/**
 * Statistiques d'une chaîne YouTube (Data API v3, permission youtube.readonly).
 *
 * La Data API ne donne que des TOTAUX : abonnés et vues de la chaîne. Les vues
 * d'un jour se déduisent donc d'une collecte à l'autre (vues_cumulees) — pas
 * d'historique avant la première collecte. Un historique jour par jour
 * demanderait l'API YouTube Analytics et sa permission propre.
 */
class CollecteurYoutube implements Collecteur
{
    private const API = 'https://www.googleapis.com/youtube/v3';

    public function __construct(private FournisseurGoogle $google) {}

    public function jours(Integration $integration, Carbon $depuis): array
    {
        $chaine = $this->chaine($integration);
        $stats  = $chaine['statistics'] ?? [];

        return [now(config('publication.fuseau'))->toDateString() => [
            // Une chaîne peut masquer son nombre d'abonnés.
            'abonnes'       => ! empty($stats['hiddenSubscriberCount']) ? null : (int) ($stats['subscriberCount'] ?? 0),
            'vues_cumulees' => (int) ($stats['viewCount'] ?? 0),
        ]];
    }

    public function publications(Integration $integration, Carbon $depuis): array
    {
        $uploads = $this->chaine($integration)['contentDetails']['relatedPlaylists']['uploads'] ?? null;
        if (! $uploads) {
            return [];
        }

        $ids   = [];
        $page  = null;
        do {
            $r = $this->get($integration, '/playlistItems', array_filter([
                'part' => 'contentDetails', 'playlistId' => $uploads, 'maxResults' => 50, 'pageToken' => $page,
            ]));
            foreach ($r['items'] ?? [] as $item) {
                if (Carbon::parse($item['contentDetails']['videoPublishedAt'] ?? 'now')->lt($depuis)) {
                    break 2;
                }
                $ids[] = $item['contentDetails']['videoId'];
            }
            $page = $r['nextPageToken'] ?? null;
        } while ($page && count($ids) < 200);

        $videos = [];
        foreach (array_chunk($ids, 50) as $lot) {
            $r = $this->get($integration, '/videos', ['part' => 'snippet,statistics,contentDetails', 'id' => implode(',', $lot)]);
            array_push($videos, ...($r['items'] ?? []));
        }

        return array_map(function ($v) {
            $s      = $v['statistics'] ?? [];
            $jaime  = isset($s['likeCount']) ? (int) $s['likeCount'] : null;
            $comm   = isset($s['commentCount']) ? (int) $s['commentCount'] : null;
            $duree  = $this->secondes($v['contentDetails']['duration'] ?? '');

            return [
                'external_id'     => $v['id'],
                // Indice seulement : YouTube classe en Short une vidéo verticale de 3 min au plus.
                'type'            => $duree !== null && $duree <= 180 ? 'short' : 'video',
                'publie_le'       => Carbon::parse($v['snippet']['publishedAt']),
                'texte'           => $v['snippet']['title'] ?? null,
                'vignette'        => $v['snippet']['thumbnails']['medium']['url'] ?? $v['snippet']['thumbnails']['default']['url'] ?? null,
                'lien'            => "https://www.youtube.com/watch?v={$v['id']}",
                'vues'            => isset($s['viewCount']) ? (int) $s['viewCount'] : null,
                'portee'          => null,
                'interactions'    => $jaime === null && $comm === null ? null : (int) $jaime + (int) $comm,
                'j_aime'          => $jaime,
                'commentaires'    => $comm,
                'partages'        => null,
                'enregistrements' => null,
            ];
        }, $videos);
    }

    // ---------------------------------------------------------------------

    private function chaine(Integration $integration): array
    {
        $r = $this->get($integration, '/channels', [
            'part' => 'statistics,contentDetails',
            'id'   => $integration->settings['channel_id'] ?? $integration->external_account_id,
        ]);

        return $r['items'][0] ?? throw new \RuntimeException('Chaîne YouTube introuvable.');
    }

    private function get(Integration $integration, string $chemin, array $params): array
    {
        $this->google->rafraichirSiBesoin($integration);

        $r = Http::timeout(30)->withToken($integration->fresh()->access_token)->get(self::API . $chemin, $params);

        if ($r->failed()) {
            throw new \RuntimeException('YouTube a refusé la lecture des statistiques : ' . ($r->json('error.message') ?? "HTTP {$r->status()}"));
        }

        return $r->json() ?? [];
    }

    /** « PT1M30S » → 90. */
    private function secondes(string $iso): ?int
    {
        try {
            $i = new \DateInterval($iso);

            return $i->d * 86400 + $i->h * 3600 + $i->i * 60 + $i->s;
        } catch (\Throwable) {
            return null;
        }
    }
}
