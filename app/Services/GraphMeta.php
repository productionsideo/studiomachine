<?php

namespace App\Services;

use App\Models\Integration;
use App\Models\SocialComment;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Client de l'API Graph de Meta — lecture des commentaires Facebook et
 * Instagram, et envoi des réponses.
 *
 * Deux plateformes, un seul jeton
 * -------------------------------
 * Facebook et Instagram passent par la même API et le même jeton de Page. Un
 * compte Instagram professionnel est rattaché à une Page Facebook ; c'est le
 * jeton de cette Page qui ouvre les deux. On garde pourtant DEUX lignes dans
 * `integrations` — une par plateforme — parce qu'un client peut très bien avoir
 * une Page sans Instagram, et parce que les commentaires doivent rester
 * distingués dans la boîte de réception.
 *
 * Ce que cette classe ne fait pas
 * -------------------------------
 * Elle ne décide jamais si une réponse doit partir. Elle exécute. La décision
 * appartient à AssistantClaude::peutPartirSeul(), et à personne d'autre.
 *
 * Elle ne lève pas d'exception vers l'appelant. Une plateforme qui tombe ne
 * doit pas faire échouer une synchronisation entière : chaque erreur est
 * consignée sur l'intégration ou sur le commentaire concerné, et le reste
 * continue.
 */
class GraphMeta
{
    private string $base;

    public function __construct()
    {
        $this->base = 'https://graph.facebook.com/' . config('meta.version');
    }

    // =====================================================================
    // LECTURE
    // =====================================================================

    /**
     * Rapatrie les commentaires d'une intégration (facebook ou instagram).
     *
     * Retourne [nombre de commentaires nouveaux, nombre mis à jour].
     * N'échoue jamais : en cas de problème, l'erreur est écrite sur
     * l'intégration et la méthode retourne des compteurs à zéro.
     */
    public function synchroniser(Integration $integration): array
    {
        $neufs = 0;
        $vus   = 0;

        $refusees = 0;

        try {
            $publications = $integration->platform === 'instagram'
                ? $this->publicationsInstagram($integration)
                : $this->publicationsFacebook($integration);

            foreach ($publications as $publication) {
                // Isolé par publication, et c'est essentiel. Une Page contient
                // toujours quelques objets que Meta refuse de servir : un partage
                // dont la source a été supprimée, une publication d'un tiers, un
                // contenu restreint. Si l'exception remontait ici, UNE publication
                // fautive ferait perdre les commentaires de toutes les suivantes —
                // et c'est exactement ce qui s'est produit au premier essai.
                try {
                    $commentaires = $integration->platform === 'instagram'
                        ? $this->commentairesInstagram($integration, $publication['id'])
                        : $this->commentairesFacebook($integration, $publication['id']);
                } catch (\Throwable $e) {
                    $refusees++;

                    Log::info('Publication ignorée à la synchronisation', [
                        'plateforme'  => $integration->platform,
                        'publication' => $publication['id'],
                        'motif'       => $e->getMessage(),
                    ]);

                    continue;
                }

                foreach ($commentaires as $brut) {
                    $etait = SocialComment::where('platform', $integration->platform)
                        ->where('external_comment_id', $brut['external_comment_id'])
                        ->exists();

                    $this->enregistrer($integration, $publication, $brut);

                    $etait ? $vus++ : $neufs++;
                }
            }

            $integration->update([
                'last_synced_at' => now(),
                // On ne masque pas les refus : s'il y en a, on le dit. Mais ce
                // n'est pas une panne — le reste est bien passé.
                'last_error' => $refusees > 0
                    ? "{$refusees} publication(s) refusée(s) par Meta ; les autres ont été lues."
                    : null,
            ]);
        } catch (\Throwable $e) {
            // On consigne et on rend la main. Une Page dont le jeton a expiré
            // ne doit pas empêcher les autres clients d'être synchronisés.
            $integration->update([
                'last_error' => mb_substr($e->getMessage(), 0, 500),
            ]);

            Log::warning("Synchronisation {$integration->platform} échouée", [
                'client'      => $integration->client_id,
                'integration' => $integration->id,
                'erreur'      => $e->getMessage(),
            ]);
        }

        return [$neufs, $vus];
    }

    /** Les publications récentes de la Page Facebook. */
    private function publicationsFacebook(Integration $integration): array
    {
        $pageId = $integration->settings['page_id']
            ?? $integration->external_account_id
            ?? throw new \RuntimeException('page_id manquant dans les réglages de l’intégration Facebook.');

        $reponse = $this->appeler("/{$pageId}/published_posts", $integration, [
            'fields' => 'id,permalink_url,created_time',
            'limit'  => config('meta.publications_par_passe'),
        ]);

        return array_map(fn ($p) => [
            'id'        => $p['id'],
            'permalink' => $p['permalink_url'] ?? null,
        ], $reponse['data'] ?? []);
    }

    /** Les publications récentes du compte Instagram professionnel. */
    private function publicationsInstagram(Integration $integration): array
    {
        $igId = $integration->settings['ig_user_id']
            ?? $integration->external_account_id
            ?? throw new \RuntimeException('ig_user_id manquant dans les réglages de l’intégration Instagram.');

        $reponse = $this->appeler("/{$igId}/media", $integration, [
            'fields' => 'id,permalink,timestamp',
            'limit'  => config('meta.publications_par_passe'),
        ]);

        return array_map(fn ($p) => [
            'id'        => $p['id'],
            'permalink' => $p['permalink'] ?? null,
        ], $reponse['data'] ?? []);
    }

    /**
     * Les commentaires d'une publication Facebook.
     *
     * `filter=stream` remonte AUSSI les réponses aux commentaires, pas seulement
     * les commentaires de premier niveau. C'est ce qu'on veut : un client qui
     * relance sous son propre commentaire attend une réponse, lui aussi.
     */
    private function commentairesFacebook(Integration $integration, string $postId): array
    {
        $limite = $this->limiteAnciennete();
        $sortie = [];

        $page = $this->appeler("/{$postId}/comments", $integration, [
            'fields' => 'id,message,created_time,from{id,name},parent{id}',
            'filter' => 'stream',
            'order'  => 'reverse_chronological',
            'limit'  => 100,
        ]);

        foreach ($page['data'] ?? [] as $c) {
            $publie = isset($c['created_time']) ? Carbon::parse($c['created_time']) : null;

            // La liste est antichronologique : au premier commentaire trop vieux,
            // tous les suivants le sont aussi. Inutile de continuer à paginer.
            if ($publie && $publie->lt($limite)) {
                break;
            }

            $sortie[] = [
                'external_comment_id' => $c['id'],
                'external_parent_id'  => $c['parent']['id'] ?? null,
                'author_name'         => $c['from']['name'] ?? null,
                'author_external_id'  => $c['from']['id'] ?? null,
                'text'                => $c['message'] ?? null,
                'posted_at'           => $publie,
            ];
        }

        return $sortie;
    }

    /**
     * Les commentaires d'une publication Instagram, réponses comprises.
     *
     * Instagram n'a pas de `filter=stream` : les réponses sont imbriquées sous
     * `replies`. On aplatit, en gardant le lien de parenté.
     */
    private function commentairesInstagram(Integration $integration, string $mediaId): array
    {
        $limite = $this->limiteAnciennete();
        $sortie = [];

        $page = $this->appeler("/{$mediaId}/comments", $integration, [
            'fields' => 'id,text,timestamp,username,replies{id,text,timestamp,username}',
            'limit'  => 100,
        ]);

        foreach ($page['data'] ?? [] as $c) {
            $publie = isset($c['timestamp']) ? Carbon::parse($c['timestamp']) : null;

            if ($publie && $publie->lt($limite)) {
                continue;   // Instagram ne garantit pas l'ordre : on filtre sans casser la boucle.
            }

            $sortie[] = [
                'external_comment_id' => $c['id'],
                'external_parent_id'  => null,
                'author_name'         => $c['username'] ?? null,
                'author_external_id'  => null,   // Instagram ne donne pas l'ID de l'auteur public
                'text'                => $c['text'] ?? null,
                'posted_at'           => $publie,
            ];

            foreach ($c['replies']['data'] ?? [] as $r) {
                $rPublie = isset($r['timestamp']) ? Carbon::parse($r['timestamp']) : null;

                if ($rPublie && $rPublie->lt($limite)) {
                    continue;
                }

                $sortie[] = [
                    'external_comment_id' => $r['id'],
                    'external_parent_id'  => $c['id'],
                    'author_name'         => $r['username'] ?? null,
                    'author_external_id'  => null,
                    'text'                => $r['text'] ?? null,
                    'posted_at'           => $rPublie,
                ];
            }
        }

        return $sortie;
    }

    /**
     * Écrit le commentaire en base, sans jamais écraser notre propre travail.
     *
     * `updateOrCreate` sur (plateforme, id externe) rend la synchronisation
     * rejouable : la repasser dix fois ne crée pas dix lignes. Et les champs
     * qui nous appartiennent — la réponse, l'analyse de Claude — ne figurent
     * pas dans la mise à jour : une resynchronisation ne doit jamais effacer
     * un brouillon validé ni redemander une analyse déjà faite.
     */
    private function enregistrer(Integration $integration, array $publication, array $brut): void
    {
        $capsulePost = \App\Models\CapsulePost::where('platform', $integration->platform)
            ->where('external_post_id', $publication['id'])
            ->first();

        SocialComment::updateOrCreate(
            [
                'platform'            => $integration->platform,
                'external_comment_id' => $brut['external_comment_id'],
            ],
            [
                'client_id'          => $integration->client_id,
                'capsule_post_id'    => $capsulePost?->id,
                'external_post_id'   => $publication['id'],
                'post_permalink'     => $publication['permalink'],
                'external_parent_id' => $brut['external_parent_id'],
                'author_name'        => $brut['author_name'],
                'author_external_id' => $brut['author_external_id'],
                'text'               => $brut['text'],
                'posted_at'          => $brut['posted_at'],
            ],
        );
    }

    // =====================================================================
    // ÉCRITURE
    // =====================================================================

    /**
     * Publie une réponse sous un commentaire.
     *
     * Retourne [succès, identifiant de la réponse ou message d'erreur].
     *
     * Le commentaire n'est PAS modifié ici : c'est l'appelant qui décide quoi
     * inscrire en base selon le résultat. Cette classe ne fait qu'obéir.
     */
    public function repondre(SocialComment $commentaire, string $texte): array
    {
        $integration = Integration::where('client_id', $commentaire->client_id)
            ->where('platform', $commentaire->platform)
            ->where('active', true)
            ->first();

        if (! $integration) {
            return [false, "Aucun compte {$commentaire->platform} connecté pour ce client."];
        }

        // Facebook : on répond en créant un commentaire enfant du commentaire.
        // Instagram : l'endpoint s'appelle /replies, et il refuse de répondre à
        // une réponse — il faut viser le commentaire de premier niveau.
        if ($commentaire->platform === 'instagram') {
            $cible  = $commentaire->external_parent_id ?: $commentaire->external_comment_id;
            $chemin = "/{$cible}/replies";
        } else {
            $chemin = "/{$commentaire->external_comment_id}/comments";
        }

        try {
            $reponse = $this->appeler($chemin, $integration, ['message' => $texte], 'post');

            if (empty($reponse['id'])) {
                return [false, 'Meta a accepté l’appel sans renvoyer d’identifiant de réponse.'];
            }

            return [true, $reponse['id']];
        } catch (\Throwable $e) {
            return [false, mb_substr($e->getMessage(), 0, 500)];
        }
    }

    // =====================================================================
    // DIAGNOSTIC
    // =====================================================================

    /**
     * Interroge Meta sur l'état réel d'un jeton : à qui il appartient, quand il
     * expire, et quelles permissions il porte VRAIMENT.
     *
     * C'est la seule façon honnête de répondre à « pourquoi ça ne marche pas ».
     * Un jeton qui n'a pas pages_read_engagement ne renverra pas d'erreur en
     * lisant les commentaires : il renverra une liste vide. Le silence ressemble
     * à « aucun commentaire » alors qu'il veut dire « pas la permission ».
     */
    public function diagnostiquer(Integration $integration): array
    {
        $appId     = config('meta.app_id');
        $appSecret = config('meta.app_secret');

        if (! $appId || ! $appSecret) {
            return ['ok' => false, 'motif' => 'META_APP_ID / META_APP_SECRET absents du .env.'];
        }

        try {
            $reponse = Http::timeout(15)
                ->get($this->base . '/debug_token', [
                    'input_token'  => $integration->access_token,
                    'access_token' => "{$appId}|{$appSecret}",
                ])
                ->throw()
                ->json('data', []);

            $expire = ! empty($reponse['expires_at'])
                ? Carbon::createFromTimestamp($reponse['expires_at'])
                : null;

            return [
                'ok'          => (bool) ($reponse['is_valid'] ?? false),
                'type'        => $reponse['type'] ?? null,
                'app_id'      => $reponse['app_id'] ?? null,
                'expire_le'   => $expire,          // null = jeton sans expiration
                'permissions' => $reponse['scopes'] ?? [],
                'motif'       => $reponse['error']['message'] ?? null,
            ];
        } catch (\Throwable $e) {
            return ['ok' => false, 'motif' => mb_substr($e->getMessage(), 0, 300)];
        }
    }

    /**
     * Échange un jeton court (1 h, celui de l'explorateur Graph) contre un
     * jeton long (60 jours). À faire une fois, au branchement du compte.
     */
    public function prolonger(string $jetonCourt): array
    {
        $appId     = config('meta.app_id');
        $appSecret = config('meta.app_secret');

        if (! $appId || ! $appSecret) {
            return [false, 'META_APP_ID / META_APP_SECRET absents du .env.'];
        }

        try {
            $reponse = Http::timeout(15)
                ->get($this->base . '/oauth/access_token', [
                    'grant_type'        => 'fb_exchange_token',
                    'client_id'         => $appId,
                    'client_secret'     => $appSecret,
                    'fb_exchange_token' => $jetonCourt,
                ])
                ->throw()
                ->json();

            return [true, $reponse['access_token']];
        } catch (\Throwable $e) {
            return [false, mb_substr($e->getMessage(), 0, 300)];
        }
    }

    // =====================================================================
    // Plomberie
    // =====================================================================

    /**
     * Un appel à Graph. Lève une exception explicite si Meta refuse : le
     * message de Meta est bien plus utile que « erreur HTTP 400 ».
     */
    private function appeler(string $chemin, Integration $integration, array $params = [], string $verbe = 'get'): array
    {
        // Meta compte les appels par application. On ne court pas.
        usleep(config('meta.delai_entre_appels_ms') * 1000);

        $params['access_token'] = $integration->access_token;

        $requete = Http::timeout(25)->retry(2, 500, throw: false);

        $reponse = $verbe === 'post'
            ? $requete->asForm()->post($this->base . $chemin, $params)
            : $requete->get($this->base . $chemin, $params);

        $corps = $reponse->json() ?? [];

        if (isset($corps['error'])) {
            $e = $corps['error'];

            throw new \RuntimeException(sprintf(
                'Meta a refusé : %s (code %s%s)',
                $e['message'] ?? 'motif inconnu',
                $e['code'] ?? '?',
                isset($e['error_subcode']) ? ', sous-code ' . $e['error_subcode'] : '',
            ));
        }

        if ($reponse->failed()) {
            throw new \RuntimeException("Meta a répondu HTTP {$reponse->status()}.");
        }

        return $corps;
    }

    private function limiteAnciennete(): Carbon
    {
        return now()->subDays(config('meta.anciennete_max_jours'));
    }
}
