<?php

namespace App\Services\Publication;

use App\Models\PostTarget;
use App\Services\Connexion\FournisseurTiktok;
use App\Services\Connexion\TiktokCreateur;
use App\Services\Connexion\TiktokErreur;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Publication directe sur TikTok (Content Posting API, « Direct Post »).
 *
 * Tant que l'application n'est pas auditée par TikTok
 * --------------------------------------------------
 *   - tout ce qui est publié est PRIVÉ (SELF_ONLY), et seuls des comptes
 *     privés peuvent recevoir une publication ;
 *   - 5 utilisateurs au plus peuvent publier par période de 24 heures.
 * Après l'audit, un plafond de créateurs actifs par jour est fixé d'après ce
 * qu'on a déclaré dans le formulaire.
 *
 * Les règles d'interface que TikTok impose (et vérifie à l'audit)
 * --------------------------------------------------------------
 *   - la confidentialité est choisie à la main, sans valeur par défaut ;
 *   - commentaires, duo et collage sont décochés par défaut, grisés si le
 *     compte les a désactivés ;
 *   - la mention de contenu commercial est explicite, et un contenu de marque
 *     ne peut pas être « Moi uniquement » ;
 *   - la personne accepte la Music Usage Confirmation.
 * L'éditeur pose ces choix dans les options de la cible ; ce publieur refuse
 * de partir s'il en manque un. On ne devine rien à la place de l'humain.
 *
 * Pourquoi FILE_UPLOAD plutôt que PULL_FROM_URL
 * ---------------------------------------------
 * PULL_FROM_URL exige de faire vérifier le domaine des médias dans le portail
 * TikTok. Le fichier est de toute façon sur notre disque : on l'envoie
 * nous-mêmes, en morceaux, sans jamais le charger entier en mémoire.
 */
class PublieurTiktok implements Publieur
{
    private const BASE = 'https://open.tiktokapis.com';

    private const CONFIDENTIALITES = ['PUBLIC_TO_EVERYONE', 'MUTUAL_FOLLOW_FRIENDS', 'FOLLOWER_OF_CREATOR', 'SELF_ONLY'];

    private const MO = 1024 * 1024;

    // Les refus passagers : rien n'est parti, on peut retenter plus tard.
    private const PASSAGERS = ['rate_limit_exceeded', 'spam_risk_too_many_pending_share', 'internal_error'];

    // Les refus qui méritent une explication plutôt que le message brut.
    private const EXPLICATIONS = [
        'unaudited_client_can_only_post_to_private_accounts' =>
            'L’application TikTok n’est pas encore auditée : seuls les comptes privés peuvent recevoir des publications.',
        'reached_active_user_cap' =>
            'Le plafond quotidien de comptes qui publient par notre application TikTok est atteint. Réessayez demain, ou demandez à TikTok de relever le plafond.',
        'spam_risk_too_many_posts' =>
            'TikTok refuse : ce compte a atteint son nombre maximal de publications pour aujourd’hui.',
        'spam_risk_user_banned_from_posting' =>
            'TikTok a interdit de publication ce compte.',
        'access_token_invalid' =>
            'Le jeton TikTok n’est plus valide : rebranchez le compte dans Intégrations.',
        'scope_not_authorized' =>
            'Le compte TikTok n’a pas accordé la permission de publier : rebranchez-le dans Intégrations.',
    ];

    public function __construct(
        private FournisseurTiktok $fournisseur,
        private TiktokCreateur $createur,
    ) {}

    public function verifier(PostTarget $cible): array
    {
        $problemes = [];
        $medias    = $cible->post->media;
        $video     = $medias->first();

        if (! $cible->integration || ! $cible->integration->active) {
            $problemes[] = 'Aucun compte TikTok actif n’est branché pour ce client.';
        }

        if ($medias->count() !== 1 || ! $video?->isVideo()) {
            $problemes[] = 'TikTok attend exactement une vidéo.';
        }

        $confidentialite = $cible->option('confidentialite');
        if (! in_array($confidentialite, self::CONFIDENTIALITES, true)) {
            $problemes[] = 'Choisissez qui peut voir la vidéo sur TikTok (aucun choix par défaut n’est permis).';
        }

        if (! $cible->option('consentement')) {
            $problemes[] = 'La confirmation d’utilisation de la musique de TikTok n’a pas été acceptée.';
        }

        if ($cible->option('contenu_commercial')) {
            if (! $cible->option('votre_marque') && ! $cible->option('contenu_marque')) {
                $problemes[] = 'Contenu commercial : précisez s’il s’agit de votre marque ou d’un contenu de marque.';
            }
            if ($cible->option('contenu_marque') && $confidentialite === 'SELF_ONLY') {
                $problemes[] = 'Un contenu de marque ne peut pas être publié en « Moi uniquement ».';
            }
        }

        if (mb_strlen($cible->texte()) > 2200) {
            $problemes[] = 'Le texte dépasse les 2 200 caractères permis par TikTok.';
        }

        if ($video?->isVideo()) {
            if ($video->size_bytes > 4 * 1024 * self::MO) {
                $problemes[] = 'La vidéo dépasse 4 Go.';
            }
            if ($video->duration_seconds && $video->duration_seconds > 600) {
                $problemes[] = 'La vidéo dépasse 10 minutes.';
            }
            if (! is_file($video->chemin())) {
                $problemes[] = 'Le fichier vidéo est introuvable sur le serveur.';
            }
        }

        return $problemes;
    }

    public function demarrer(PostTarget $cible): Etape
    {
        $integration = $cible->integration;
        $video       = $cible->post->media->first();

        // --- Avant init : rien n'est parti, un échec passager se retente. ---

        try {
            $this->fournisseur->rafraichirSiBesoin($integration);
            $integration->refresh();

            // Toujours une réponse fraîche au moment de publier : le compte a
            // pu changer ses réglages depuis la préparation de la publication.
            $compte = $this->createur->infos($integration, fraiche: true);
        } catch (TiktokErreur $e) {
            return $this->refus($e->codeTiktok, $e->getMessage(), avantEnvoi: true);
        } catch (ConnectionException $e) {
            return Etape::echec('TikTok injoignable : ' . $e->getMessage(), reessayable: true);
        } catch (\RuntimeException $e) {
            return Etape::echec($e->getMessage());
        }

        $confidentialite = $cible->option('confidentialite');

        if (! in_array($confidentialite, $compte['confidentialites'], true)) {
            return Etape::echec(sprintf(
                'Ce compte TikTok n’autorise pas la confidentialité choisie. Choix possibles : %s.',
                implode(', ', $compte['confidentialites']) ?: 'aucun',
            ));
        }

        if ($compte['duree_max'] && $video->duration_seconds && $video->duration_seconds > $compte['duree_max']) {
            return Etape::echec("Ce compte TikTok accepte des vidéos de {$compte['duree_max']} s au plus.");
        }

        $taille   = filesize($video->chemin());
        $decoupe  = $this->decouper($taille);

        $marque = (bool) $cible->option('contenu_commercial');

        try {
            $reponse = Http::timeout(30)
                ->withToken($integration->access_token)
                ->asJson()
                ->post(self::BASE . '/v2/post/publish/video/init/', [
                    'post_info' => [
                        'title'                => $cible->texte(),
                        'privacy_level'        => $confidentialite,
                        // Ce que le compte a désactivé reste désactivé, quoi
                        // qu'on ait coché.
                        'disable_comment'      => $compte['commentaires_bloques'] || ! $cible->option('commentaires', false),
                        'disable_duet'         => $compte['duo_bloque'] || ! $cible->option('duo', false),
                        'disable_stitch'       => $compte['collage_bloque'] || ! $cible->option('collage', false),
                        'is_aigc'              => (bool) $cible->option('ia', false),
                        'brand_content_toggle' => $marque && (bool) $cible->option('contenu_marque', false),
                        'brand_organic_toggle' => $marque && (bool) $cible->option('votre_marque', false),
                    ],
                    'source_info' => [
                        'source'            => 'FILE_UPLOAD',
                        'video_size'        => $taille,
                        'chunk_size'        => $decoupe['taille'],
                        'total_chunk_count' => $decoupe['nombre'],
                    ],
                ]);
        } catch (ConnectionException $e) {
            // La requête n'a pas abouti : sans publish_id, rien ne peut sortir.
            return Etape::echec('TikTok injoignable : ' . $e->getMessage(), reessayable: true);
        }

        $corps = $reponse->json() ?? [];
        $code  = $corps['error']['code'] ?? ($reponse->successful() ? 'ok' : 'http_' . $reponse->status());

        if ($code !== 'ok' || empty($corps['data']['publish_id']) || empty($corps['data']['upload_url'])) {
            return $this->refus(
                $code,
                $corps['error']['message'] ?? "TikTok a répondu HTTP {$reponse->status()}.",
                avantEnvoi: true,
                serveur: $reponse->serverError(),
            );
        }

        $publishId = $corps['data']['publish_id'];

        // --- Après init : le publish_id existe. Tout échec est définitif. ---

        try {
            $this->envoyerMorceaux($corps['data']['upload_url'], $video->chemin(), $video->mime, $taille, $decoupe);
        } catch (\Throwable $e) {
            return Etape::echec("Envoi du fichier à TikTok interrompu (publish_id {$publishId}) : " . $e->getMessage());
        }

        return Etape::enCours($publishId);
    }

    public function poursuivre(PostTarget $cible): Etape
    {
        $integration = $cible->integration;
        $publishId   = $cible->external_job_id;

        try {
            $this->fournisseur->rafraichirSiBesoin($integration);
            $integration->refresh();

            $reponse = Http::timeout(20)
                ->withToken($integration->access_token)
                ->asJson()
                ->post(self::BASE . '/v2/post/publish/status/fetch/', ['publish_id' => $publishId]);
        } catch (\Throwable $e) {
            // On ne sait pas où en est TikTok : on repassera voir, sans rien
            // renvoyer. L'ordonnanceur abandonne de lui-même passé le délai.
            return Etape::enCours($publishId);
        }

        $corps = $reponse->json() ?? [];
        $code  = $corps['error']['code'] ?? ($reponse->successful() ? 'ok' : 'http_' . $reponse->status());

        if ($code !== 'ok') {
            return in_array($code, self::PASSAGERS, true) || $reponse->serverError()
                ? Etape::enCours($publishId)
                : Etape::echec($this->expliquer($code, $corps['error']['message'] ?? ''));
        }

        $donnees = $corps['data'] ?? [];
        $statut  = $donnees['status'] ?? '';

        if ($statut === 'PUBLISH_COMPLETE') {
            // Une vidéo privée (compte non audité, ou SELF_ONLY) n'a pas
            // d'identifiant public : on garde alors le publish_id.
            // « publicaly » : l'orthographe est celle de l'API TikTok.
            $idPublic = $donnees['publicaly_available_post_id'][0] ?? null;
            $pseudo   = $integration->settings['username'] ?? null;

            return Etape::publiee(
                (string) ($idPublic ?? $publishId),
                $idPublic && $pseudo ? "https://www.tiktok.com/@{$pseudo}/video/{$idPublic}" : null,
            );
        }

        if ($statut === 'FAILED') {
            return Etape::echec('TikTok a rejeté la vidéo : ' . ($donnees['fail_reason'] ?? 'motif inconnu') . '.');
        }

        // PROCESSING_UPLOAD, PROCESSING_DOWNLOAD… : TikTok travaille encore.
        return Etape::enCours($publishId);
    }

    /**
     * Le découpage que TikTok accepte : morceaux de 5 à 64 Mo, le dernier
     * pouvant aller jusqu'à 128 Mo puisqu'il absorbe le reste. Sous 5 Mo, un
     * seul morceau de la taille du fichier.
     */
    private function decouper(int $taille): array
    {
        $morceau = 10 * self::MO;

        // Sous 5 Mo c'est obligatoire ; jusqu'à 10 Mo, un seul morceau de la
        // taille exacte du fichier évite d'annoncer un morceau plus grand que lui.
        if ($taille < $morceau) {
            return ['taille' => $taille, 'nombre' => 1];
        }

        // Le dernier morceau vaut morceau + reste < 2 × morceau : à 64 Mo il
        // tient toujours sous 128 Mo. On grossit seulement si le nombre de
        // morceaux dépasserait la limite de 1 000.
        while (intdiv($taille, $morceau) > 1000 && $morceau < 64 * self::MO) {
            $morceau = min($morceau * 2, 64 * self::MO);
        }

        return ['taille' => $morceau, 'nombre' => max(1, intdiv($taille, $morceau))];
    }

    /** Envoie le fichier morceau par morceau, dans l'ordre, sans le charger entier. */
    private function envoyerMorceaux(string $url, string $chemin, string $mime, int $taille, array $decoupe): void
    {
        $flux = fopen($chemin, 'rb') ?: throw new \RuntimeException('Fichier vidéo illisible.');

        try {
            for ($i = 0; $i < $decoupe['nombre']; $i++) {
                $debut = $i * $decoupe['taille'];
                $fin   = $i === $decoupe['nombre'] - 1 ? $taille - 1 : $debut + $decoupe['taille'] - 1;
                $longueur = $fin - $debut + 1;

                fseek($flux, $debut);
                $octets = '';
                while (strlen($octets) < $longueur && ! feof($flux)) {
                    $octets .= fread($flux, $longueur - strlen($octets));
                }

                $reponse = Http::timeout(300)
                    ->withHeaders([
                        'Content-Range' => "bytes {$debut}-{$fin}/{$taille}",
                    ])
                    ->withBody($octets, $mime ?: 'video/mp4')
                    ->put($url);

                unset($octets);

                if (! $reponse->successful()) {
                    throw new \RuntimeException("morceau " . ($i + 1) . "/{$decoupe['nombre']} refusé (HTTP {$reponse->status()}).");
                }
            }
        } finally {
            fclose($flux);
        }
    }

    /** Traduit un refus de TikTok en Etape, retentable ou non. */
    private function refus(string $code, string $message, bool $avantEnvoi, bool $serveur = false): Etape
    {
        $passager = $avantEnvoi && ($serveur || in_array($code, self::PASSAGERS, true));

        return Etape::echec($this->expliquer($code, $message), reessayable: $passager);
    }

    private function expliquer(string $code, string $message): string
    {
        return self::EXPLICATIONS[$code] ?? trim("TikTok a refusé ({$code}) : {$message}");
    }
}
