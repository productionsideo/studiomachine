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
 *
 * Avec Cloudflare R2
 * ------------------
 * Quand R2 est configuré, une vidéo ne passe plus du tout par le serveur : le
 * navigateur la dépose directement chez R2 (envoi en plusieurs parties, voir
 * debuterEnvoiDirect) et en lit lui-même durée, dimensions et vignette. Lancé
 * depuis une page web, ffprobe se bloquait sur le serveur (30 s pour une
 * analyse de 0,2 s en ligne de commande) : on ne dépend plus de lui là.
 * Les images gardent le chemin en morceaux (conversion JPEG côté serveur),
 * puis rejoignent R2.
 */
class Mediatheque
{
    public function __construct(private R2 $r2) {}

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

        $nom    = Str::random(40) . '.' . $extension;
        $chemin = "{$dossier}/{$nom}";
        File::move($source, $chemin);
        @chmod($chemin, 0644);

        // Une image se mesure sans ffprobe (qui se bloque quand il est lancé
        // depuis une page web sur le serveur).
        $infos = $genre === 'image' ? $this->mesurerImage($chemin) : $this->sonder($chemin);

        $media = MediaAsset::create([
            'client_id'        => $client->id,
            'uploaded_by'      => $auteur?->id,
            'kind'             => $genre,
            'original_name'    => mb_substr($nomOriginal, 0, 255),
            'filename'         => $nom,
            'mime'             => $mime,
            'size_bytes'       => filesize($chemin),
            'width'            => $infos['width'] ?? null,
            'height'           => $infos['height'] ?? null,
            'duration_seconds' => $genre === 'video' ? ($infos['duration'] ?? null) : null,
        ]);

        if ($genre === 'video') {
            $media->update(['thumbnail' => $this->vignette($media)]);
        }

        if (R2::actif()) {
            $this->versR2($media);
        }

        return $media;
    }

    /** Déplace sur R2 un média rangé sur le disque du serveur. */
    private function versR2(MediaAsset $media): void
    {
        $dossier = config('publication.medias.dossier');

        $this->r2->deposer($media->filename, "{$dossier}/{$media->filename}", $media->mime);
        if ($media->thumbnail) {
            $this->r2->deposer($media->thumbnail, "{$dossier}/{$media->thumbnail}", 'image/jpeg');
        }

        $media->update(['disk' => 'r2']);

        @unlink("{$dossier}/{$media->filename}");
        if ($media->thumbnail) {
            @unlink("{$dossier}/{$media->thumbnail}");
        }
    }

    // --- Envoi direct navigateur → R2 (vidéos) -------------------------------

    /**
     * Ouvre un envoi en plusieurs parties chez R2 et signe une adresse par
     * partie. Le nom du fichier chez R2 est tiré ici, jamais par le navigateur.
     */
    public function debuterEnvoiDirect(Client $client, User $auteur, string $nomOriginal, int $taille, string $mime): array
    {
        $types = config('publication.medias.types');

        if (($types[$mime][0] ?? null) !== 'video') {
            throw new \RuntimeException('Seules les vidéos MP4 ou MOV passent par l’envoi direct.');
        }
        if ($taille < 1 || $taille > config('publication.medias.max_octets')) {
            throw new \RuntimeException('Fichier trop lourd (1 Go au maximum).');
        }

        $cle    = Str::random(40) . '.' . $types[$mime][1];
        $envoi  = $this->r2->creerEnvoi($cle, $mime);
        $part   = (int) config('publication.medias.r2.part_octets');
        $nombre = max(1, (int) ceil($taille / $part));

        // Ce que le navigateur ne pourra pas changer à la fin de l'envoi.
        cache()->put("envoi-r2:{$envoi}", [
            'client' => $client->id,
            'auteur' => $auteur->id,
            'cle'    => $cle,
            'nom'    => mb_substr($nomOriginal, 0, 255),
            'taille' => $taille,
        ], now()->addDay());

        return [
            'envoi'  => $envoi,
            'part'   => $part,
            'urls'   => array_map(fn ($n) => $this->r2->adressePartie($cle, $envoi, $n), range(1, $nombre)),
        ];
    }

    /**
     * Assemble les parties, vérifie le fichier (taille, vrai format) et le
     * range. Durée, dimensions et vignette viennent du navigateur.
     *
     * @param array<int, string> $parts numéro => ETag
     */
    public function terminerEnvoiDirect(
        Client $client,
        string $envoi,
        array $parts,
        array $infos,
        ?UploadedFile $vignette,
    ): MediaAsset {
        $e = cache()->get("envoi-r2:{$envoi}");

        if (! $e || $e['client'] !== $client->id) {
            throw new \RuntimeException('Envoi inconnu ou expiré. Recommencez.');
        }

        try {
            $this->r2->terminerEnvoi($e['cle'], $envoi, $parts);
        } catch (\Throwable $ex) {
            $this->r2->abandonnerEnvoi($e['cle'], $envoi);
            throw $ex;
        }
        cache()->forget("envoi-r2:{$envoi}");

        // Le type se lit dans le contenu, comme pour un dépôt classique.
        $taille = $this->r2->entete($e['cle'])['taille'] ?? 0;
        $mime   = (new \finfo(FILEINFO_MIME_TYPE))->buffer($this->r2->debut($e['cle']));
        $types  = config('publication.medias.types');

        if ($taille !== $e['taille'] || ($types[$mime][0] ?? null) !== 'video') {
            $this->r2->supprimer($e['cle']);
            throw new \RuntimeException($taille !== $e['taille']
                ? 'Le fichier reçu est incomplet. Recommencez l’envoi.'
                : "Format non pris en charge ({$mime}). Vidéos MP4 ou MOV.");
        }

        $nomVignette = null;
        if ($vignette && (new \finfo(FILEINFO_MIME_TYPE))->file($vignette->getRealPath()) === 'image/jpeg') {
            $nomVignette = Str::random(40) . '.jpg';
            $this->r2->deposer($nomVignette, $vignette->getRealPath(), 'image/jpeg');
        }

        $nombre = fn ($v, $max) => is_numeric($v) && $v > 0 && $v <= $max ? $v : null;

        return MediaAsset::create([
            'client_id'        => $client->id,
            'uploaded_by'      => $e['auteur'],
            'kind'             => 'video',
            'original_name'    => $e['nom'],
            'filename'         => $e['cle'],
            'thumbnail'        => $nomVignette,
            'mime'             => $mime,
            'disk'             => 'r2',
            'size_bytes'       => $taille,
            'width'            => ($l = $nombre($infos['largeur'] ?? null, 16384)) ? (int) $l : null,
            'height'           => ($h = $nombre($infos['hauteur'] ?? null, 16384)) ? (int) $h : null,
            'duration_seconds' => ($d = $nombre($infos['duree'] ?? null, 86400)) ? round((float) $d, 2) : null,
        ]);
    }

    public function abandonnerEnvoiDirect(Client $client, string $envoi): void
    {
        $e = cache()->pull("envoi-r2:{$envoi}");

        if ($e && $e['client'] === $client->id) {
            $this->r2->abandonnerEnvoi($e['cle'], $envoi);
        }
    }

    /** Supprime un média que plus aucune publication n'utilise. */
    public function supprimer(MediaAsset $media): void
    {
        if ($media->posts()->exists()) {
            throw new \RuntimeException('Ce média est utilisé par une publication. Retirez-le d’abord de la publication.');
        }

        if ($media->surR2()) {
            $this->r2->supprimer($media->filename);
            if ($media->thumbnail) {
                $this->r2->supprimer($media->thumbnail);
            }
            @unlink(config('publication.medias.cache') . '/' . $media->filename);
        } else {
            $dossier = config('publication.medias.dossier');

            @unlink("{$dossier}/{$media->filename}");
            if ($media->thumbnail) {
                @unlink("{$dossier}/{$media->thumbnail}");
            }
        }

        $media->delete();
    }

    /** Efface les copies locales de médias R2 de plus d'un jour. */
    public static function nettoyerCache(): int
    {
        $n = 0;
        foreach (glob(config('publication.medias.cache') . '/*') ?: [] as $f) {
            if (is_file($f) && filemtime($f) < time() - 86400) {
                @unlink($f) && $n++;
            }
        }

        return $n;
    }

    private function mesurerImage(string $chemin): array
    {
        $taille = @getimagesize($chemin);

        return $taille ? ['width' => $taille[0], 'height' => $taille[1]] : [];
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

        // Un ffprobe bloqué ne doit jamais faire échouer un dépôt : le média
        // est rangé sans ses caractéristiques (medias:analyser les complète).
        try {
            $process->run();
        } catch (\Symfony\Component\Process\Exception\RuntimeException) {
            return [];
        }

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

        try {
            $process->run();
        } catch (\Symfony\Component\Process\Exception\RuntimeException) {
            @unlink($cible);

            return null;
        }

        return $process->isSuccessful() && is_file($cible) ? $nom : null;
    }
}
