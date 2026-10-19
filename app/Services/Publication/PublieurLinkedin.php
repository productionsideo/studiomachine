<?php

namespace App\Services\Publication;

use App\Models\MediaAsset;
use App\Models\PostTarget;
use App\Services\Connexion\FournisseurLinkedin;
use App\Services\Connexion\FournisseurLinkedinProfil;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Publication sur LinkedIn par l'API Posts : Page entreprise (« linkedin ») ou
 * profil personnel (« linkedin_profil ») — même API, seul l'auteur change.
 *
 * Ce qui rend LinkedIn simple à fiabiliser : un média déposé n'est visible de
 * personne tant qu'aucune publication ne le cite. Tout ce qui précède l'appel
 * à /rest/posts peut donc se retenter sans risque de doublon. Seul cet appel
 * final est délicat : s'il n'aboutit pas clairement, on s'arrête.
 *
 * Contenus pris en charge (vérifiés le 2026-10-19, version 202610)
 * ---------------------------------------------------------------
 *   - texte seul ;
 *   - une image, ou de 2 à 20 images (multiImage) — JPEG, PNG ou GIF ;
 *   - une vidéo MP4 de 3 s à 30 min, 500 Mo au plus. LinkedIn la traite
 *     ensuite : on ne publie qu'une fois son statut AVAILABLE (poursuivre).
 *
 * Le texte suit le format « little » de LinkedIn : certains caractères
 * (parenthèses, crochets, @, _…) y sont réservés et doivent être échappés,
 * sinon LinkedIn tronque le texte au premier rencontré — sans erreur.
 */
class PublieurLinkedin implements Publieur
{
    private const TEXTE_MAX = 3000;

    private const IMAGES = ['image/jpeg', 'image/png', 'image/gif'];

    // Réservés par le format little. « # » reste tel quel : c'est lui qui
    // fait les mots-clics.
    private const RESERVES = ['\\', '|', '{', '}', '@', '[', ']', '(', ')', '<', '>', '*', '_', '~'];

    public function __construct(private ClientLinkedin $api) {}

    public function verifier(PostTarget $cible): array
    {
        if (! $cible->integration?->active) {
            return ['Aucun compte LinkedIn actif n’est branché pour ce client.'];
        }

        $problemes = [];
        $medias    = $cible->post->media;
        $videos    = $medias->filter->isVideo();

        if ($videos->isNotEmpty()) {
            $video = $videos->first();

            if ($medias->count() > 1) {
                $problemes[] = 'Une publication LinkedIn contient soit des images, soit une seule vidéo — pas les deux.';
            }

            if ($video->mime !== 'video/mp4') {
                $problemes[] = 'LinkedIn n’accepte que les vidéos MP4.';
            }

            if ($video->duration_seconds !== null && ($video->duration_seconds < 3 || $video->duration_seconds > 1800)) {
                $problemes[] = sprintf('Une vidéo LinkedIn dure de 3 secondes à 30 minutes (celle-ci : %d s).', $video->duration_seconds);
            }

            if ($video->size_bytes > 500 * 1024 * 1024) {
                $problemes[] = 'La vidéo dépasse les 500 Mo permis par LinkedIn.';
            }
        } else {
            if ($medias->count() > 20) {
                $problemes[] = 'LinkedIn accepte 20 images au plus par publication.';
            }

            if ($medias->contains(fn (MediaAsset $m) => ! in_array($m->mime, self::IMAGES, true))) {
                $problemes[] = 'LinkedIn accepte les images JPEG, PNG ou GIF seulement.';
            }
        }

        if ($medias->contains(fn (MediaAsset $m) => ! $m->disponible())) {
            $problemes[] = 'Un fichier est introuvable sur le serveur.';
        }

        $texte = $cible->texte();

        if ($medias->isEmpty() && trim($texte) === '') {
            $problemes[] = 'La publication est vide : ni texte, ni média.';
        }

        if (mb_strlen($texte) > self::TEXTE_MAX) {
            $problemes[] = 'Le texte dépasse les 3 000 caractères permis par LinkedIn.';
        }

        return $problemes;
    }

    public function demarrer(PostTarget $cible): Etape
    {
        $integration = $cible->integration;

        try {
            $this->fournisseur($cible)->rafraichirSiBesoin($integration);
            $integration->refresh();
        } catch (\Throwable $e) {
            return Etape::echec($e->getMessage(), reessayable: ! str_contains($e->getMessage(), 'rebranché'));
        }

        $jeton  = $integration->access_token;
        $auteur = $this->auteur($cible);
        $medias = $cible->post->media;

        // --- Dépôt des médias : invisibles tant qu'aucune publication ne les cite.

        try {
            if ($medias->first()?->isVideo()) {
                // Le traitement de la vidéo prend de quelques secondes à
                // plusieurs minutes : la publication part dans poursuivre().
                return Etape::enCours($this->deposerVideo($jeton, $auteur, $medias->first()));
            }

            $images = $medias->map(fn (MediaAsset $m) => $this->deposerImage($jeton, $auteur, $m))->all();
        } catch (ErreurReseau $e) {
            return Etape::echec($e->getMessage(), reessayable: $e->passagere);
        } catch (\Throwable $e) {
            return Etape::echec('Dépôt du média sur LinkedIn impossible : ' . $e->getMessage(), reessayable: true);
        }

        $contenu = match (count($images)) {
            0       => null,
            1       => ['media' => ['id' => $images[0]]],
            default => ['multiImage' => ['images' => array_map(fn ($urn) => ['id' => $urn], $images)]],
        };

        return $this->publier($cible, $jeton, $auteur, $contenu, enAttente: null);
    }

    public function poursuivre(PostTarget $cible): Etape
    {
        $integration = $cible->integration;
        $video       = $cible->external_job_id;

        try {
            $this->fournisseur($cible)->rafraichirSiBesoin($integration);
            $integration->refresh();

            $etat = $this->api->appeler($integration->access_token, 'get', '/videos/' . ClientLinkedin::urn($video));
        } catch (\Throwable $e) {
            // On ne sait pas où en est LinkedIn : on repassera. Rien n'est
            // publié tant qu'on n'a pas appelé /rest/posts.
            return Etape::enCours($video);
        }

        return match ($etat->json('status')) {
            'AVAILABLE'         => $this->publier($cible, $integration->access_token, $this->auteur($cible), ['media' => ['id' => $video]], enAttente: $video),
            'PROCESSING_FAILED' => Etape::echec('LinkedIn n’a pas pu traiter la vidéo : ' . ($etat->json('processingFailureReason') ?? 'motif inconnu') . '.', reessayable: true),
            default             => Etape::enCours($video),   // PROCESSING, WAITING_UPLOAD
        };
    }

    // =====================================================================
    // Plomberie
    // =====================================================================

    /**
     * Crée la publication. C'est le seul appel qui rend quelque chose visible.
     *
     * @param ?string $enAttente l'URN de la vidéo si l'on vient de poursuivre() :
     *                           un refus passager y garde la cible en traitement.
     */
    private function publier(PostTarget $cible, string $jeton, string $auteur, ?array $contenu, ?string $enAttente): Etape
    {
        $corps = array_filter([
            'author'       => $auteur,
            'commentary'   => $this->echapper($cible->texte()),
            'visibility'   => 'PUBLIC',
            'distribution' => [
                'feedDistribution'               => 'MAIN_FEED',
                'targetEntities'                 => [],
                'thirdPartyDistributionChannels' => [],
            ],
            'content'                   => $contenu,
            'lifecycleState'            => 'PUBLISHED',
            'isReshareDisabledByAuthor' => false,
        ], fn ($v) => $v !== null);

        try {
            $reponse = $this->api->requete($jeton, 60)->asJson()->post(ClientLinkedin::BASE . '/posts', $corps);
        } catch (ConnectionException $e) {
            return Etape::echec('La demande de publication à LinkedIn a été interrompue (' . $e->getMessage() . '). Vérifiez sur LinkedIn si elle est sortie avant de relancer.');
        }

        $id = $reponse->header('x-restli-id');

        if ($reponse->successful() && $id) {
            return Etape::publiee($id, "https://www.linkedin.com/feed/update/{$id}/");
        }

        // 429 : refusé d'emblée, rien n'est sorti. 5xx : LinkedIn a pu créer
        // la publication avant de flancher — on ne prend pas le risque.
        if ($reponse->status() === 429) {
            return $enAttente
                ? Etape::enCours($enAttente)
                : Etape::echec('Limite de débit LinkedIn atteinte : ' . $this->api->motif($reponse), reessayable: true);
        }

        return Etape::echec('LinkedIn a refusé la publication : ' . $this->api->motif($reponse)
            . ($reponse->serverError() ? '. Vérifiez sur LinkedIn si elle est sortie avant de relancer.' : '.'));
    }

    /** Dépose une image et retourne son URN (urn:li:image:…). */
    private function deposerImage(string $jeton, string $auteur, MediaAsset $image): string
    {
        $init = $this->api->appeler($jeton, 'post', '/images', ['initializeUploadRequest' => ['owner' => $auteur]], ['action' => 'initializeUpload']);

        $url = $init->json('value.uploadUrl') ?? throw new ErreurReseau('LinkedIn n’a pas fourni d’adresse de dépôt pour l’image.');

        $envoi = $this->api->requete($jeton, 120)
            ->withBody(file_get_contents($image->fichierLocal()), $image->mime)
            ->put($url);

        if ($envoi->failed()) {
            throw new ErreurReseau("Image refusée par LinkedIn (HTTP {$envoi->status()}).", passagere: $envoi->serverError() || $envoi->status() === 429);
        }

        return $init->json('value.image');
    }

    /**
     * Dépose une vidéo en plusieurs parties, dans l'ordre que LinkedIn fixe
     * (environ 4 Mo chacune), puis la finalise. Retourne son URN.
     */
    private function deposerVideo(string $jeton, string $auteur, MediaAsset $video): string
    {
        $fichier = $video->fichierLocal();
        $taille  = filesize($fichier);

        $init = $this->api->appeler($jeton, 'post', '/videos', ['initializeUploadRequest' => [
            'owner'           => $auteur,
            'fileSizeBytes'   => $taille,
            'uploadCaptions'  => false,
            'uploadThumbnail' => false,
        ]], ['action' => 'initializeUpload']);

        $urn          = $init->json('value.video') ?? throw new ErreurReseau('LinkedIn n’a pas fourni d’identifiant pour la vidéo.');
        $instructions = $init->json('value.uploadInstructions', []);
        $parties      = [];

        $flux = fopen($fichier, 'rb') ?: throw new \RuntimeException('Fichier vidéo illisible.');

        try {
            foreach ($instructions as $i => $partie) {
                $longueur = $partie['lastByte'] - $partie['firstByte'] + 1;

                fseek($flux, $partie['firstByte']);
                $octets = '';
                while (strlen($octets) < $longueur && ! feof($flux)) {
                    $octets .= fread($flux, $longueur - strlen($octets));
                }

                $envoi = Http::timeout(300)
                    ->withBody($octets, 'application/octet-stream')
                    ->put($partie['uploadUrl']);

                unset($octets);

                if ($envoi->failed() || ! $envoi->header('ETag')) {
                    throw new ErreurReseau(sprintf('Partie %d/%d de la vidéo refusée par LinkedIn (HTTP %d).', $i + 1, count($instructions), $envoi->status()), passagere: true);
                }

                $parties[] = trim($envoi->header('ETag'), '"');
            }
        } finally {
            fclose($flux);
        }

        $this->api->appeler($jeton, 'post', '/videos', ['finalizeUploadRequest' => [
            'video'           => $urn,
            'uploadToken'     => $init->json('value.uploadToken', ''),
            'uploadedPartIds' => $parties,
        ]], ['action' => 'finalizeUpload']);

        return $urn;
    }

    /** Chaque réseau a son application LinkedIn, donc ses propres identifiants de renouvellement. */
    private function fournisseur(PostTarget $cible): FournisseurLinkedin
    {
        return app($cible->platform === 'linkedin_profil' ? FournisseurLinkedinProfil::class : FournisseurLinkedin::class);
    }

    private function auteur(PostTarget $cible): string
    {
        return $cible->integration->settings['auteur'] ?? $cible->integration->external_account_id;
    }

    private function echapper(string $texte): string
    {
        return strtr($texte, array_combine(self::RESERVES, array_map(fn ($c) => '\\' . $c, self::RESERVES)));
    }
}
