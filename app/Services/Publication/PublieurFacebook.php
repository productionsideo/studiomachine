<?php

namespace App\Services\Publication;

use App\Models\PostTarget;
use Illuminate\Support\Facades\Http;

/**
 * Publication sur une Page Facebook : publication classique (texte, lien,
 * photos, vidéo) ou Reel.
 *
 * Meta va chercher les fichiers lui-même à leur URL publique (file_url /
 * url) : rien ne transite par la mémoire de PHP, et une vidéo de 300 Mo part
 * aussi vite qu'une photo.
 *
 * Limites documentées (vérifiées le 2026-09-30) : Reel de 3 à 90 s, 9:16,
 * 30 Reels publiés par API sur 24 h glissantes. Exige pages_manage_posts et
 * publish_video, en accès avancé pour les Pages des clients.
 */
class PublieurFacebook implements Publieur
{
    public function __construct(private ClientGraph $graphe) {}

    public function verifier(PostTarget $cible): array
    {
        $problemes = [];
        $medias    = $cible->post->media;
        $videos    = $medias->filter->isVideo();

        if (! $cible->integration?->active) {
            return ['Aucune Page Facebook connectée pour ce client.'];
        }

        if ($cible->format === 'reel') {
            $video = $videos->first();

            if ($medias->count() !== 1 || ! $video) {
                $problemes[] = 'Un Reel Facebook demande exactement une vidéo.';
            } elseif ($video->duration_seconds !== null && ($video->duration_seconds < 3 || $video->duration_seconds > 90)) {
                $problemes[] = sprintf('Un Reel Facebook dure de 3 à 90 secondes (cette vidéo : %d s).', $video->duration_seconds);
            } elseif ($video->ratio() !== null && ! $video->estVertical()) {
                $problemes[] = 'Un Reel Facebook doit être vertical (9:16).';
            }
        } else {
            if ($videos->count() > 1 || ($videos->count() === 1 && $medias->count() > 1)) {
                $problemes[] = 'Une publication Facebook contient soit des photos, soit une seule vidéo — pas les deux.';
            }

            if ($medias->isEmpty() && trim($cible->texte()) === '') {
                $problemes[] = 'La publication est vide : ni texte, ni média.';
            }
        }

        if (mb_strlen($cible->texte()) > Reseaux::CATALOGUE['facebook']['texte_max']) {
            $problemes[] = 'Texte trop long pour Facebook.';
        }

        return $problemes;
    }

    public function demarrer(PostTarget $cible): Etape
    {
        $page   = $cible->integration->settings['page_id'] ?? $cible->integration->external_account_id;
        $jeton  = $cible->integration->access_token;
        $texte  = $cible->texte();
        $medias = $cible->post->media;

        try {
            if ($cible->format === 'reel') {
                return $this->reel($page, $jeton, $texte, $medias->first());
            }

            if ($medias->isEmpty()) {
                $r = $this->graphe->appeler("/{$page}/feed", $jeton, ['message' => $texte], 'post');

                return Etape::publiee($r['id'], $this->permalien($r['id'], $jeton));
            }

            if ($medias->first()->isVideo()) {
                // La vidéo est créée d'emblée publiée ; Meta la traite ensuite,
                // et on repasse vérifier qu'elle n'a pas été rejetée.
                $r = $this->graphe->appeler("/{$page}/videos", $jeton, [
                    'file_url'    => $medias->first()->url(),
                    'description' => $texte,
                ], 'post');

                return Etape::enCours($r['id']);
            }

            if ($medias->count() === 1) {
                $r = $this->graphe->appeler("/{$page}/photos", $jeton, [
                    'url'     => $medias->first()->url(),
                    'message' => $texte,
                ], 'post');

                $id = $r['post_id'] ?? $r['id'];

                return Etape::publiee($id, $this->permalien($id, $jeton));
            }

            return $this->album($page, $jeton, $texte, $medias);
        } catch (ErreurReseau $e) {
            // Toutes ces requêtes créent la publication en un seul appel : si
            // Meta répond par une erreur, rien n'est sorti. On ne retente que
            // si Meta dit l'erreur passagère.
            return Etape::echec($e->getMessage(), $e->passagere);
        }
    }

    public function poursuivre(PostTarget $cible): Etape
    {
        $jeton = $cible->integration->access_token;

        try {
            $video = $this->graphe->appeler("/{$cible->external_job_id}", $jeton, [
                'fields' => 'status,permalink_url',
            ]);
        } catch (ErreurReseau $e) {
            // Simple lecture : on repassera.
            return Etape::enCours($cible->external_job_id);
        }

        $statut = $video['status']['video_status'] ?? null;

        if ($statut === 'error') {
            $detail = $video['status']['processing_phase']['errors'][0]['message']
                ?? $video['status']['uploading_phase']['errors'][0]['message']
                ?? 'motif non précisé';

            return Etape::echec("Facebook a rejeté la vidéo pendant son traitement : {$detail}");
        }

        $publiee = ($video['status']['publishing_phase']['status'] ?? null) === 'complete'
            || ($cible->format !== 'reel' && $statut === 'ready');

        if (! $publiee) {
            return Etape::enCours($cible->external_job_id);
        }

        $lien = $video['permalink_url'] ?? null;
        if ($lien && str_starts_with($lien, '/')) {
            $lien = 'https://www.facebook.com' . $lien;
        }

        return Etape::publiee($cible->external_job_id, $lien);
    }

    /**
     * Un Reel se publie en trois temps : on réserve un identifiant, Meta
     * télécharge le fichier, puis on lui dit de publier. Seul le troisième
     * rend quoi que ce soit visible : un échec avant lui ne laisse qu'une
     * vidéo orpheline, invisible, et on peut retenter sans doublon.
     */
    private function reel(string $page, string $jeton, string $texte, $video): Etape
    {
        $debut = $this->graphe->appeler("/{$page}/video_reels", $jeton, ['upload_phase' => 'start'], 'post');
        $id    = $debut['video_id'];

        $envoi = Http::timeout(120)
            ->withHeaders([
                'Authorization' => "OAuth {$jeton}",
                'file_url'      => $video->url(),
            ])
            ->post('https://rupload.facebook.com/video-upload/' . config('meta.version') . "/{$id}");

        if (! ($envoi->json('success') ?? false)) {
            return Etape::echec(
                'Meta n’a pas pu récupérer la vidéo : ' . ($envoi->json('debug_info.message') ?? $envoi->json('error.message') ?? "HTTP {$envoi->status()}"),
                reessayable: true,
            );
        }

        try {
            $this->graphe->appeler("/{$page}/video_reels", $jeton, [
                'upload_phase' => 'finish',
                'video_id'     => $id,
                'video_state'  => 'PUBLISHED',
                'description'  => $texte,
            ], 'post');
        } catch (ErreurReseau $e) {
            // Ici on ne sait plus : Meta a peut-être publié malgré l'erreur.
            return Etape::echec($e->getMessage() . ' — vérifiez la Page avant de relancer.');
        }

        return Etape::enCours($id);
    }

    /**
     * Plusieurs photos : chacune est déposée sans être publiée, puis une
     * seule publication les rassemble. Les photos non publiées sont
     * invisibles : un échec en route ne laisse rien sur la Page.
     */
    private function album(string $page, string $jeton, string $texte, $medias): Etape
    {
        $params = ['message' => $texte];

        foreach ($medias->values() as $i => $media) {
            $photo = $this->graphe->appeler("/{$page}/photos", $jeton, [
                'url'       => $media->url(),
                'published' => 'false',
            ], 'post');

            $params["attached_media[{$i}]"] = json_encode(['media_fbid' => $photo['id']]);
        }

        $r = $this->graphe->appeler("/{$page}/feed", $jeton, $params, 'post');

        return Etape::publiee($r['id'], $this->permalien($r['id'], $jeton));
    }

    private function permalien(string $id, string $jeton): ?string
    {
        try {
            return $this->graphe->appeler("/{$id}", $jeton, ['fields' => 'permalink_url'])['permalink_url'] ?? null;
        } catch (ErreurReseau) {
            return null;    // la publication est faite ; le lien n'est qu'un confort
        }
    }
}
