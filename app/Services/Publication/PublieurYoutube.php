<?php

namespace App\Services\Publication;

use App\Models\PostTarget;
use App\Services\Connexion\FournisseurGoogle;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Publication sur YouTube (Short ou vidéo) par l'envoi « resumable » de la
 * Data API v3.
 *
 * Deux temps, mais un seul appel de notre côté
 * --------------------------------------------
 *   1. POST des métadonnées → YouTube ouvre une session d'envoi (en-tête Location).
 *   2. PUT du fichier sur cette session → la vidéo EXISTE dès la réponse.
 * Le traitement qui suit chez YouTube (encodage, définitions) ne nous concerne
 * pas : la vidéo est sur la chaîne, avec la confidentialité demandée. D'où
 * poursuivre() inutilisé.
 *
 * Un Short n'est pas un format d'API : YouTube classe de lui-même en Short une
 * vidéo verticale ou carrée de 3 minutes au plus. On n'ajoute donc rien au
 * titre ; on vérifie seulement que la vidéo remplit les conditions.
 *
 * À savoir avant de s'étonner
 * ---------------------------
 *   - Tant que le projet Google Cloud n'a pas passé l'audit de conformité de
 *     YouTube, TOUTE vidéo envoyée par l'API est forcée en privé, quoi qu'on
 *     demande. Ce n'est pas un bogue de ce code.
 *   - Depuis le 2026-06-01, le quota par défaut est de 100 appels
 *     videos.insert par jour pour l'ensemble du projet — donc pour tous les
 *     clients de l'agence réunis.
 */
class PublieurYoutube implements Publieur
{
    private const ENVOI = 'https://www.googleapis.com/upload/youtube/v3/videos';

    public function __construct(private FournisseurGoogle $google) {}

    public function verifier(PostTarget $cible): array
    {
        $problemes = [];

        if (! $cible->integration || ! $cible->integration->active) {
            $problemes[] = 'Aucune chaîne YouTube active n’est branchée pour ce client.';
        }

        $medias = $cible->post->media;

        if ($medias->count() !== 1 || ! $medias->first()->isVideo()) {
            $problemes[] = 'YouTube exige exactement une vidéo, et rien d’autre.';
        } elseif ($cible->format === 'short') {
            $video = $medias->first();

            if ($video->duration_seconds !== null && $video->duration_seconds > 180) {
                $problemes[] = sprintf('Un Short dure 3 minutes au plus ; cette vidéo en fait %d s. Choisissez le format « Vidéo ».', $video->duration_seconds);
            }

            if (($video->ratio() ?? 1) > 1) {
                $problemes[] = 'Un Short doit être vertical ou carré ; cette vidéo est horizontale. Choisissez le format « Vidéo ».';
            }
        }

        $titre = trim((string) $cible->option('titre', ''));

        if ($titre === '') {
            $problemes[] = 'Le titre YouTube est obligatoire.';
        } elseif (mb_strlen($titre) > 100) {
            $problemes[] = 'Le titre YouTube dépasse 100 caractères.';
        }

        // YouTube refuse ces deux caractères dans le titre comme dans la
        // description, avec un message d'erreur qui ne dit pas lequel.
        if (str_contains($titre, '<') || str_contains($titre, '>')) {
            $problemes[] = 'Le titre YouTube ne peut pas contenir « < » ni « > ».';
        }

        $description = $cible->texte();

        if (strlen($description) > 5000) {
            $problemes[] = 'La description YouTube dépasse 5 000 octets (les accents et émojis comptent double ou plus).';
        }

        if (str_contains($description, '<') || str_contains($description, '>')) {
            $problemes[] = 'La description YouTube ne peut pas contenir « < » ni « > ».';
        }

        return $problemes;
    }

    public function demarrer(PostTarget $cible): Etape
    {
        $integration = $cible->integration;
        $video       = $cible->post->media->first();

        // --- Avant la session : rien n'existe encore chez YouTube ----------
        //
        // Tout échec ici est sans risque de doublon : on peut retenter.

        try {
            $this->google->rafraichirSiBesoin($integration);
        } catch (\Throwable $e) {
            // Un accès révoqué ne reviendra pas seul ; un Google injoignable, si.
            return Etape::echec($e->getMessage(), reessayable: ! str_contains($e->getMessage(), 'rebranché'));
        }

        $taille = filesize($video->chemin());

        if ($taille === false) {
            return Etape::echec('Le fichier vidéo est introuvable sur le serveur.');
        }

        try {
            $session = Http::timeout(30)
                ->withToken($integration->fresh()->access_token)
                ->withHeaders([
                    'X-Upload-Content-Length' => (string) $taille,
                    'X-Upload-Content-Type'   => $video->mime,
                ])
                ->withQueryParameters(['uploadType' => 'resumable', 'part' => 'snippet,status'])
                ->post(self::ENVOI, $this->metadonnees($cible));
        } catch (\Throwable $e) {
            return Etape::echec('YouTube injoignable : ' . $e->getMessage(), reessayable: true);
        }

        if ($session->failed() || ! $session->header('Location')) {
            return $this->refusAvantEnvoi($session);
        }

        // --- Le fichier : à partir d'ici, la vidéo peut exister -------------
        //
        // Si le PUT échoue en route, YouTube a pu tout recevoir quand même.
        // On ne retente donc jamais seul : un humain vérifie la chaîne.

        $flux = fopen($video->chemin(), 'rb');

        try {
            $envoi = Http::timeout(900)
                ->withToken($integration->access_token)
                ->withBody(Utils::streamFor($flux), $video->mime)
                ->put($session->header('Location'));
        } catch (\Throwable $e) {
            return Etape::echec('L’envoi de la vidéo a été interrompu (' . $e->getMessage() . '). Vérifiez dans YouTube Studio si elle est arrivée avant de relancer.');
        } finally {
            if (is_resource($flux)) {
                fclose($flux);
            }
        }

        $id = $envoi->json('id');

        if ($envoi->failed() || ! $id) {
            return Etape::echec('YouTube a refusé la vidéo : ' . $this->motif($envoi)
                . '. Vérifiez dans YouTube Studio qu’elle n’est pas arrivée avant de relancer.');
        }

        return Etape::publiee($id, $cible->format === 'short'
            ? "https://www.youtube.com/shorts/{$id}"
            : "https://www.youtube.com/watch?v={$id}");
    }

    public function poursuivre(PostTarget $cible): Etape
    {
        // L'envoi YouTube se conclut en un seul passage : une cible YouTube
        // restée « en traitement » signale un état incohérent, pas une attente.
        return Etape::echec('État inattendu : un envoi YouTube ne reste jamais en traitement. Vérifiez la chaîne dans YouTube Studio.');
    }

    // =====================================================================
    // Plomberie
    // =====================================================================

    private function metadonnees(PostTarget $cible): array
    {
        $confidentialite = $cible->option('confidentialite', 'public');

        $snippet = [
            'title'       => trim((string) $cible->option('titre')),
            'description' => $cible->texte(),
            'categoryId'  => (string) $cible->option('categorie', '22'),
        ];

        if ($tags = $cible->option('tags')) {
            $snippet['tags'] = is_array($tags)
                ? array_values($tags)
                : array_values(array_filter(array_map('trim', explode(',', $tags))));
        }

        return [
            'snippet' => $snippet,
            'status'  => [
                'privacyStatus'           => in_array($confidentialite, ['public', 'unlisted', 'private'], true) ? $confidentialite : 'public',
                'selfDeclaredMadeForKids' => false,
                // Divulgation exigée par YouTube pour un contenu réaliste
                // généré ou modifié par IA — ce que produit Studio Machine.
                'containsSyntheticMedia'  => (bool) $cible->option('ia', false),
            ],
        ];
    }

    /** Refus à l'ouverture de la session : rien n'a été envoyé. */
    private function refusAvantEnvoi(Response $reponse): Etape
    {
        $raison = $reponse->json('error.errors.0.reason');

        if (in_array($raison, ['quotaExceeded', 'uploadLimitExceeded', 'rateLimitExceeded', 'userRateLimitExceeded'], true)) {
            return Etape::echec(
                'Quota YouTube atteint (' . $raison . ') : le projet ne peut plus envoyer de vidéos pour aujourd’hui. Le quota se renouvelle à minuit, heure du Pacifique.',
                reessayable: true,
            );
        }

        // 5xx et 429 : passager. 401/403/400 : ça ne changera pas tout seul.
        $passager = $reponse->serverError() || $reponse->status() === 429;

        return Etape::echec('YouTube a refusé l’envoi : ' . $this->motif($reponse), reessayable: $passager);
    }

    private function motif(Response $reponse): string
    {
        $message = $reponse->json('error.message') ?? "HTTP {$reponse->status()}";
        $raison  = $reponse->json('error.errors.0.reason');

        return $raison ? "{$message} ({$raison})" : $message;
    }
}
