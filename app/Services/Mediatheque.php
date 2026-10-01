<?php

namespace App\Services;

use App\Models\Client;
use App\Models\MediaAsset;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

/**
 * Réception et rangement des images et vidéos à publier.
 *
 * Pourquoi en morceaux
 * --------------------
 * Le PHP du serveur refuse toute requête de plus de 25 Mo, et ce réglage est
 * partagé par les 80 comptes de l'agence : on ne le monte pas pour nous. Le
 * navigateur découpe donc le fichier en morceaux de 5 Mo, qu'on recolle ici
 * dans l'ordre. Une vidéo de 400 Mo passe sans toucher à la configuration.
 *
 * Pourquoi ffprobe
 * ----------------
 * Chaque réseau a ses exigences (durée, ratio, poids). Les connaître au
 * moment du dépôt permet de prévenir dans l'éditeur, plutôt que d'apprendre à
 * 9 h, par un refus d'Instagram, que la vidéo était en 16:9.
 */
class Mediatheque
{
    /**
     * La taille des morceaux envoyés par le navigateur.
     *
     * Elle doit tenir sous upload_max_filesize ET post_max_size du PHP qui
     * reçoit — 25 Mo sur le serveur de l'agence, mais 2 Mo par défaut sur une
     * installation neuve. On garde 256 Ko de marge pour l'enveloppe de la
     * requête (les autres champs du formulaire).
     */
    public static function tailleMorceau(): int
    {
        $octets = static function (string $valeur): int {
            $valeur = trim($valeur);
            $n = (int) $valeur;

            return match (strtolower(substr($valeur, -1))) {
                'g' => $n * 1024 ** 3,
                'm' => $n * 1024 ** 2,
                'k' => $n * 1024,
                default => $n,
            };
        };

        $limites = array_filter([
            $octets((string) ini_get('upload_max_filesize')),
            $octets((string) ini_get('post_max_size')) - 256 * 1024,
        ], fn ($l) => $l > 0);

        return max(256 * 1024, min(config('publication.medias.morceau_octets'), ...($limites ?: [PHP_INT_MAX])));
    }

    /**
     * Ajoute un morceau à un envoi en cours. Retourne le média une fois le
     * dernier morceau reçu, null sinon.
     */
    public function recevoirMorceau(
        Client $client,
        User $auteur,
        string $envoiId,
        int $index,
        int $total,
        string $nomOriginal,
        UploadedFile $morceau,
    ): ?MediaAsset {
        // L'identifiant vient du navigateur : on ne lui laisse rien d'autre
        // que des caractères sûrs, pour qu'il ne puisse pas désigner un
        // fichier hors du dossier temporaire.
        if (! preg_match('/^[A-Za-z0-9]{16,64}$/', $envoiId)) {
            throw new \InvalidArgumentException('Identifiant d’envoi invalide.');
        }

        $dossier = config('publication.medias.temporaire');
        File::ensureDirectoryExists($dossier);

        $partiel = "{$dossier}/{$client->id}-{$envoiId}.part";

        // Les morceaux arrivent dans l'ordre (le navigateur attend la réponse
        // avant d'envoyer le suivant) ; on vérifie quand même, un morceau
        // perdu ou rejoué donnerait un fichier corrompu sans erreur visible.
        $attendu = $index === 0 ? 0 : (int) @filesize($partiel);
        if ($index === 0) {
            @unlink($partiel);
        } elseif ($attendu !== $index * self::tailleMorceau()) {
            @unlink($partiel);
            throw new \RuntimeException('Un morceau du fichier s’est perdu en route. Recommencez l’envoi.');
        }

        file_put_contents($partiel, file_get_contents($morceau->getRealPath()), FILE_APPEND);

        if (filesize($partiel) > config('publication.medias.max_octets')) {
            @unlink($partiel);
            throw new \RuntimeException('Fichier trop lourd (1 Go au maximum).');
        }

        if ($index < $total - 1) {
            return null;
        }

        try {
            return $this->ranger($client, $auteur, $partiel, $nomOriginal);
        } finally {
            @unlink($partiel);
        }
    }

    /** Valide le fichier complet, le range, et en tire ses caractéristiques. */
    public function ranger(Client $client, ?User $auteur, string $source, string $nomOriginal): MediaAsset
    {
        // Le type se lit dans le contenu, jamais dans le nom ni dans ce que
        // le navigateur déclare.
        $mime  = (new \finfo(FILEINFO_MIME_TYPE))->file($source);
        $types = config('publication.medias.types');

        if (! isset($types[$mime])) {
            throw new \RuntimeException("Format non pris en charge ({$mime}). Images JPEG, PNG, WebP ; vidéos MP4 ou MOV.");
        }

        [$genre, $extension] = $types[$mime];

        // Instagram n'accepte que le JPEG. On convertit dès l'import plutôt
        // qu'au moment de publier : la publication ne doit dépendre de rien
        // qui puisse rater à 9 h du matin. (Une transparence PNG est perdue,
        // aplatie sur du blanc — sans objet sur un réseau social.)
        if ($genre === 'image' && $mime !== 'image/jpeg') {
            $source    = $this->versJpeg($source);
            $mime      = 'image/jpeg';
            $extension = 'jpg';
        }

        $dossier = config('publication.medias.dossier');
        File::ensureDirectoryExists($dossier);

        $nom = Str::random(40) . '.' . $extension;
        File::move($source, "{$dossier}/{$nom}");
        @chmod("{$dossier}/{$nom}", 0644);

        $infos = $this->sonder("{$dossier}/{$nom}");

        $media = MediaAsset::create([
            'client_id'        => $client->id,
            'uploaded_by'      => $auteur?->id,
            'kind'             => $genre,
            'original_name'    => mb_substr($nomOriginal, 0, 255),
            'filename'         => $nom,
            'mime'             => $mime,
            'size_bytes'       => filesize("{$dossier}/{$nom}"),
            'width'            => $infos['width'] ?? null,
            'height'           => $infos['height'] ?? null,
            'duration_seconds' => $genre === 'video' ? ($infos['duration'] ?? null) : null,
        ]);

        if ($genre === 'video') {
            $media->update(['thumbnail' => $this->vignette($media)]);
        }

        return $media;
    }

    /** Supprime un média que plus aucune publication n'utilise. */
    public function supprimer(MediaAsset $media): void
    {
        if ($media->posts()->exists()) {
            throw new \RuntimeException('Ce média est utilisé par une publication. Retirez-le d’abord de la publication.');
        }

        $dossier = config('publication.medias.dossier');

        @unlink("{$dossier}/{$media->filename}");
        if ($media->thumbnail) {
            @unlink("{$dossier}/{$media->thumbnail}");
        }

        $media->delete();
    }

    /** Largeur, hauteur et durée, selon ffprobe. Vide si ffprobe est absent. */
    public function sonder(string $chemin): array
    {
        // Sortie complète plutôt qu'une sélection de champs : le serveur a
        // ffprobe 4.4, qui refuse les sections récentes (stream_side_data)
        // et faisait alors échouer toute l'analyse. -show_streams marche
        // partout ; on cherche la rotation aux deux endroits possibles.
        $process = new Process([
            config('publication.medias.ffprobe'),
            '-v', 'error',
            '-select_streams', 'v:0',
            '-show_streams',
            '-show_format',
            '-of', 'json',
            $chemin,
        ]);
        $process->setTimeout(30);
        $process->run();

        if (! $process->isSuccessful()) {
            return [];
        }

        $json    = json_decode($process->getOutput(), true) ?? [];
        $flux    = $json['streams'][0] ?? [];
        $largeur = $flux['width'] ?? null;
        $hauteur = $flux['height'] ?? null;

        // Un téléphone filme souvent « couché » avec une consigne de rotation :
        // la vidéo s'affiche verticale mais ses dimensions brutes disent
        // l'inverse. Sans ce redressement, un Reel 9:16 passerait pour du 16:9.
        // ffprobe récent la range dans side_data_list, l'ancien dans tags.rotate.
        $rotation = (int) ($flux['tags']['rotate'] ?? 0);
        foreach ($flux['side_data_list'] ?? [] as $donnee) {
            if (isset($donnee['rotation'])) {
                $rotation = (int) $donnee['rotation'];
            }
        }
        $rotation = abs($rotation) % 360;
        if ($rotation === 90 || $rotation === 270) {
            [$largeur, $hauteur] = [$hauteur, $largeur];
        }

        $duree = $json['format']['duration'] ?? $flux['duration'] ?? null;

        return [
            'width'    => $largeur,
            'height'   => $hauteur,
            'duration' => $duree !== null ? round((float) $duree, 2) : null,
        ];
    }

    /** Une image tirée de la vidéo, pour la médiathèque et le calendrier. */
    private function vignette(MediaAsset $media): ?string
    {
        $nom = Str::random(40) . '.jpg';
        $cible = config('publication.medias.dossier') . '/' . $nom;

        $process = new Process([
            config('publication.medias.ffmpeg'),
            '-v', 'error',
            '-ss', (string) min(1, ($media->duration_seconds ?? 2) / 2),
            '-i', $media->chemin(),
            '-frames:v', '1',
            '-vf', 'scale=480:-2',
            '-q:v', '4',
            '-y', $cible,
        ]);
        $process->setTimeout(60);
        $process->run();

        return $process->isSuccessful() && is_file($cible) ? $nom : null;
    }
}
