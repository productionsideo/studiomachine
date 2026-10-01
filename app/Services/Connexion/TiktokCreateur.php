<?php

namespace App\Services\Connexion;

use App\Models\Integration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Ce que TikTok permet, à cet instant, pour un compte donné.
 *
 * TikTok impose d'interroger creator_info avant chaque publication et de
 * construire l'interface à partir de sa réponse : la liste des niveaux de
 * confidentialité proposés, les interactions que le compte a désactivées
 * (à griser), la durée maximale de vidéo. Un éditeur qui afficherait des
 * choix en dur échouerait à l'audit.
 *
 * L'appel est limité à 20 par minute : l'éditeur lit une version gardée cinq
 * minutes ; la publication, elle, redemande toujours une réponse fraîche.
 */
class TiktokCreateur
{
    private const URL = 'https://open.tiktokapis.com/v2/post/publish/creator_info/query/';

    public function __construct(private FournisseurTiktok $fournisseur) {}

    public function infos(Integration $integration, bool $fraiche = false): array
    {
        $cle = "tiktok-createur-{$integration->id}";

        if ($fraiche) {
            Cache::forget($cle);
        }

        return Cache::remember($cle, now()->addMinutes(5), fn () => $this->interroger($integration));
    }

    private function interroger(Integration $integration): array
    {
        $this->fournisseur->rafraichirSiBesoin($integration);

        $reponse = Http::timeout(20)
            ->withToken($integration->fresh()->access_token)
            ->asJson()
            ->post(self::URL, (object) []);

        $corps = $reponse->json() ?? [];
        $code  = $corps['error']['code'] ?? ($reponse->successful() ? 'ok' : 'http_' . $reponse->status());

        if ($code !== 'ok') {
            throw new TiktokErreur($code, $corps['error']['message'] ?? "TikTok a répondu HTTP {$reponse->status()}.");
        }

        $d = $corps['data'] ?? [];

        return [
            'pseudo'               => $d['creator_username'] ?? null,
            'nom'                  => $d['creator_nickname'] ?? null,
            'avatar'               => $d['creator_avatar_url'] ?? null,
            'confidentialites'     => $d['privacy_level_options'] ?? [],
            'commentaires_bloques' => (bool) ($d['comment_disabled'] ?? false),
            'duo_bloque'           => (bool) ($d['duet_disabled'] ?? false),
            'collage_bloque'       => (bool) ($d['stitch_disabled'] ?? false),
            'duree_max'            => isset($d['max_video_post_duration_sec']) ? (int) $d['max_video_post_duration_sec'] : null,
        ];
    }
}
