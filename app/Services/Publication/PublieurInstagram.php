<?php

namespace App\Services\Publication;

use App\Models\PostTarget;

/**
 * Publication sur un compte Instagram professionnel : Reel, image,
 * carrousel ou story.
 *
 * Instagram n'a pas de programmation native : c'est notre planificateur qui
 * publie à l'heure dite. Le flux est toujours le même :
 *
 *   1. créer un « conteneur » (Instagram télécharge le média à son URL) ;
 *   2. attendre qu'il soit FINISHED ;
 *   3. media_publish — le seul appel qui rend quelque chose visible.
 *
 * Un conteneur non publié est invisible et expire seul en 24 h : tout échec
 * avant l'étape 3 peut donc être retenté sans risque de doublon.
 *
 * Limites documentées (vérifiées le 2026-09-30) : 100 publications par API sur
 * 24 h glissantes ; images JPEG seulement ; Reel de 3 s à 15 min et 300 Mo ;
 * story vidéo de 60 s et 100 Mo au plus ; carrousel de 2 à 10 éléments.
 */
class PublieurInstagram implements Publieur
{
    public function __construct(private ClientGraph $graphe) {}

    public function verifier(PostTarget $cible): array
    {
        if (! $cible->integration?->active) {
            return ['Aucun compte Instagram connecté pour ce client.'];
        }

        $medias = $cible->post->media;
        $p      = [];

        foreach ($medias as $m) {
            if (! $m->isVideo() && $m->mime !== 'image/jpeg') {
                $p[] = "Instagram n’accepte que des images JPEG ({$m->original_name}).";
            }
        }

        switch ($cible->format) {
            case 'reel':
                $v = $medias->first();
                if ($medias->count() !== 1 || ! $v?->isVideo()) {
                    $p[] = 'Un Reel demande exactement une vidéo.';
                } else {
                    if ($v->duration_seconds !== null && ($v->duration_seconds < 3 || $v->duration_seconds > 900)) {
                        $p[] = 'Un Reel dure de 3 secondes à 15 minutes.';
                    }
                    if ($v->size_bytes > 300 * 1024 * 1024) {
                        $p[] = 'Un Reel pèse 300 Mo au plus.';
                    }
                }
                break;

            case 'image':
                $i = $medias->first();
                if ($medias->count() !== 1 || $i?->isVideo()) {
                    $p[] = 'Une publication image demande exactement une image.';
                } elseif ($i->ratio() !== null && ($i->ratio() < 0.79 || $i->ratio() > 1.92)) {
                    $p[] = 'Instagram accepte les images du 4:5 (portrait) au 1,91:1 (paysage).';
                }
                break;

            case 'carrousel':
                if ($medias->count() < 2 || $medias->count() > 10) {
                    $p[] = 'Un carrousel contient de 2 à 10 médias.';
                }
                break;

            case 'story':
                $s = $medias->first();
                if ($medias->count() !== 1) {
                    $p[] = 'Une story demande exactement un média.';
                } elseif ($s->isVideo() && (($s->duration_seconds ?? 0) > 60 || $s->size_bytes > 100 * 1024 * 1024)) {
                    $p[] = 'Une story vidéo dure 60 secondes et pèse 100 Mo au plus.';
                }
                break;
        }

        if (mb_strlen($cible->texte()) > Reseaux::CATALOGUE['instagram']['texte_max']) {
            $p[] = 'La légende dépasse 2 200 caractères.';
        }

        if (substr_count($cible->texte(), '#') > 30) {
            $p[] = 'Instagram refuse plus de 30 mots-clics.';
        }

        return $p;
    }

    public function demarrer(PostTarget $cible): Etape
    {
        $ig     = $this->compte($cible);
        $jeton  = $cible->integration->access_token;
        $medias = $cible->post->media;
        $texte  = $cible->texte();

        try {
            if ($cible->format === 'carrousel') {
                $enfants = [];
                foreach ($medias as $m) {
                    $enfants[] = $this->graphe->appeler("/{$ig}/media", $jeton, array_filter([
                        'is_carousel_item' => 'true',
                        'media_type'       => $m->isVideo() ? 'VIDEO' : null,
                        'video_url'        => $m->isVideo() ? $m->url() : null,
                        'image_url'        => $m->isVideo() ? null : $m->url(),
                    ]), 'post')['id'];
                }

                // Les enfants vidéo doivent être traités avant qu'on puisse
                // créer le carrousel : on y revient à la prochaine passe.
                return Etape::enCours('enfants:' . implode(',', $enfants));
            }

            $m = $medias->first();

            $params = match ($cible->format) {
                'reel'  => ['media_type' => 'REELS', 'video_url' => $m->url(), 'caption' => $texte, 'share_to_feed' => 'true'],
                'story' => $m->isVideo()
                    ? ['media_type' => 'STORIES', 'video_url' => $m->url()]
                    : ['media_type' => 'STORIES', 'image_url' => $m->url()],
                default => ['image_url' => $m->url(), 'caption' => $texte],
            };

            $conteneur = $this->graphe->appeler("/{$ig}/media", $jeton, $params, 'post')['id'];

            // Une image est prête presque tout de suite : inutile d'attendre
            // une minute de plus pour la publier.
            if (! $m->isVideo()) {
                return $this->publierSiPret($cible, $conteneur);
            }

            return Etape::enCours($conteneur);
        } catch (ErreurReseau $e) {
            // Rien n'est visible tant que media_publish n'a pas eu lieu.
            return Etape::echec($e->getMessage(), $e->passagere);
        }
    }

    public function poursuivre(PostTarget $cible): Etape
    {
        $job = $cible->external_job_id;

        try {
            if (str_starts_with($job, 'enfants:')) {
                return $this->assemblerCarrousel($cible, explode(',', substr($job, 8)));
            }

            return $this->publierSiPret($cible, $job);
        } catch (ErreurReseau $e) {
            return $e->passagere
                ? Etape::enCours($job)
                : Etape::echec($e->getMessage());
        }
    }

    /** Vérifie l'état du conteneur, et publie s'il est prêt. */
    private function publierSiPret(PostTarget $cible, string $conteneur): Etape
    {
        $ig    = $this->compte($cible);
        $jeton = $cible->integration->access_token;

        $etat = $this->graphe->appeler("/{$conteneur}", $jeton, ['fields' => 'status_code,status']);

        switch ($etat['status_code'] ?? null) {
            case 'FINISHED':
                break;
            case 'IN_PROGRESS':
                return Etape::enCours($conteneur);
            case 'EXPIRED':
                return Etape::echec('Le conteneur Instagram a expiré (24 h) sans être publié.', reessayable: true);
            default:
                return Etape::echec('Instagram a refusé le média : ' . ($etat['status'] ?? $etat['status_code'] ?? 'motif inconnu'));
        }

        try {
            $media = $this->graphe->appeler("/{$ig}/media_publish", $jeton, ['creation_id' => $conteneur], 'post')['id'];
        } catch (ErreurReseau $e) {
            // Le seul appel qui publie. S'il échoue, on ne sait pas toujours
            // si Instagram l'a fait quand même : un humain regarde.
            return Etape::echec($e->getMessage() . ' — vérifiez le compte Instagram avant de relancer.');
        }

        $lien = null;
        try {
            $lien = $this->graphe->appeler("/{$media}", $jeton, ['fields' => 'permalink'])['permalink'] ?? null;
        } catch (ErreurReseau) {
            // Publié quand même ; le lien n'est qu'un confort.
        }

        return Etape::publiee($media, $lien);
    }

    private function assemblerCarrousel(PostTarget $cible, array $enfants): Etape
    {
        $jeton = $cible->integration->access_token;

        foreach ($enfants as $enfant) {
            $etat = $this->graphe->appeler("/{$enfant}", $jeton, ['fields' => 'status_code,status']);

            if (($etat['status_code'] ?? null) === 'IN_PROGRESS') {
                return Etape::enCours('enfants:' . implode(',', $enfants));
            }

            if (($etat['status_code'] ?? null) !== 'FINISHED') {
                return Etape::echec('Un élément du carrousel a été refusé : ' . ($etat['status'] ?? $etat['status_code'] ?? 'motif inconnu'));
            }
        }

        $parent = $this->graphe->appeler('/' . $this->compte($cible) . '/media', $jeton, [
            'media_type' => 'CAROUSEL',
            'children'   => implode(',', $enfants),
            'caption'    => $cible->texte(),
        ], 'post')['id'];

        return $this->publierSiPret($cible, $parent);
    }

    private function compte(PostTarget $cible): string
    {
        return $cible->integration->settings['ig_user_id'] ?? $cible->integration->external_account_id;
    }
}
