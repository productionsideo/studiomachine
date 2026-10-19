<?php

namespace App\Services\Publication;

use App\Models\FichierAttendu;
use App\Models\MediaAsset;
use App\Models\Post;

/**
 * Rapproche les fichiers nommés dans un import CSV et ceux de la médiathèque,
 * par leur nom d'origine (sans tenir compte de la casse ni du dossier).
 *
 * Deux moments :
 *   - à l'import, pour ce qui est déjà déposé (resoudre) ;
 *   - à chaque nouveau média, pour ce qui était attendu (rattacher), appelé
 *     par MediaAsset une fois le fichier rangé.
 *
 * On ne touche jamais une publication déjà partie : la ligne attendue reste,
 * visible, et un humain décide.
 */
class FichiersAttendus
{
    /** Rattache un média qui vient d'arriver à toutes les publications qui l'attendaient. */
    public function rattacher(MediaAsset $media): int
    {
        $attendus = FichierAttendu::with('post.targets')
            ->where('client_id', $media->client_id)
            ->where('nom_normalise', FichierAttendu::normaliser($media->original_name))
            ->get();

        $faits = 0;

        foreach ($attendus as $attendu) {
            if ($this->appliquer($attendu, $media)) {
                $faits++;
            }
        }

        return $faits;
    }

    /** À l'import : rattache tout de suite ce qui est déjà dans la médiathèque. */
    public function resoudre(Post $post): void
    {
        foreach ($post->fichiersAttendus()->get() as $attendu) {
            $media = MediaAsset::where('client_id', $post->client_id)
                ->latest('id')
                ->get()
                ->first(fn ($m) => FichierAttendu::normaliser($m->original_name) === $attendu->nom_normalise);

            if ($media) {
                $this->appliquer($attendu, $media);
            }
        }
    }

    private function appliquer(FichierAttendu $attendu, MediaAsset $media): bool
    {
        $post = $attendu->post;

        if (! $post || ! $post->estModifiable()) {
            return false;
        }

        if ($attendu->role === 'miniature') {
            if ($media->isVideo()) {
                return false;   // une miniature est une image ; on laisse la ligne en attente
            }
            $post->update(['cover_media_id' => $media->id]);
        } else {
            $post->media()->syncWithoutDetaching([$media->id => ['position' => $attendu->position]]);
        }

        $attendu->delete();

        return true;
    }
}
