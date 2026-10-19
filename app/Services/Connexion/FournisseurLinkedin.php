<?php

namespace App\Services\Connexion;

use App\Models\Integration;
use App\Services\Publication\ClientLinkedin;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

/**
 * Branchement d'un compte LinkedIn : le profil d'une personne, ou une Page
 * entreprise qu'elle administre.
 *
 * Deux produits LinkedIn, deux portées
 * ------------------------------------
 *   - « Share on LinkedIn » + « Sign In with LinkedIn using OpenID Connect »
 *     (openid profile w_member_social) : libre-service, immédiat, mais publie
 *     seulement sur le PROFIL de la personne qui se connecte.
 *   - « Community Management API » (w_organization_social
 *     r_organization_social rw_organization_admin) : publie sur les PAGES
 *     entreprise. Accordé après examen par LinkedIn, et sur une application
 *     où il est le seul produit.
 * Les permissions demandées viennent donc de LINKEDIN_SCOPES : demander une
 * permission que l'application n'a pas fait échouer toute la connexion. Le
 * code s'adapte à ce que LinkedIn accorde réellement.
 *
 * La durée des jetons
 * -------------------
 * Un jeton d'accès vit 60 jours. Seules les applications approuvées (MDP,
 * Community Management) reçoivent aussi un jeton de renouvellement, valable
 * un an. Sans lui, il faut rebrancher le compte tous les deux mois : la page
 * Intégrations affiche la date limite.
 */
class FournisseurLinkedin implements FournisseurOAuth
{
    private const OAUTH = 'https://www.linkedin.com/oauth/v2';

    /** Les rôles de Page qui permettent de publier au nom de l'entreprise. */
    private const ROLES_EDITEURS = ['ADMINISTRATOR', 'CONTENT_ADMINISTRATOR'];

    public function __construct(private ClientLinkedin $api) {}

    public function urlAutorisation(string $etat, string $retour): string
    {
        return self::OAUTH . '/authorization?' . http_build_query([
            'response_type' => 'code',
            'client_id'     => config('services.linkedin.client_id'),
            'redirect_uri'  => $retour,
            'state'         => $etat,
            'scope'         => config('services.linkedin.scopes'),
        ], '', '&', PHP_QUERY_RFC3986);
    }

    public function recevoir(Request $request, string $retour): array
    {
        if ($request->filled('error')) {
            throw new \RuntimeException(in_array($request->query('error'), ['user_cancelled_login', 'user_cancelled_authorize'], true)
                ? 'Connexion annulée sur LinkedIn : aucun compte n’a été branché.'
                : 'LinkedIn a interrompu le branchement : ' . $request->query('error_description', $request->query('error')) . '.');
        }

        $code = $request->query('code')
            ?? throw new \RuntimeException('LinkedIn n’a renvoyé aucun code d’autorisation.');

        $jetons = $this->demanderJetons([
            'grant_type'   => 'authorization_code',
            'code'         => $code,
            'redirect_uri' => $retour,
        ]) ?? throw new \RuntimeException('LinkedIn a jugé le code d’autorisation invalide ou expiré. Recommencez le branchement.');

        $accordees = $this->permissions($jetons['scope'] ?? config('services.linkedin.scopes'));
        $commun    = [
            'platform'         => 'linkedin',
            'access_token'     => $jetons['access_token'],
            'refresh_token'    => $jetons['refresh_token'] ?? null,
            'token_expires_at' => now()->addSeconds((int) ($jetons['expires_in'] ?? 60 * 86400)),
            'scopes'           => implode(',', $accordees),
        ];

        $comptes = [];

        if (in_array('w_member_social', $accordees, true) && in_array('openid', $accordees, true)) {
            $moi = Http::timeout(20)->withToken($jetons['access_token'])->get('https://api.linkedin.com/v2/userinfo');

            if ($moi->successful() && $moi->json('sub')) {
                $urn = 'urn:li:person:' . $moi->json('sub');
                $nom = $moi->json('name') ?: trim($moi->json('given_name') . ' ' . $moi->json('family_name'));

                $comptes[] = $commun + [
                    'groupe'              => $urn,
                    'libelle'             => "{$nom}  ·  profil personnel",
                    'external_account_id' => $urn,
                    'account_name'        => $nom,
                    'settings'            => ['auteur' => $urn, 'type' => 'membre'],
                ];
            }
        }

        if (in_array('w_organization_social', $accordees, true)) {
            foreach ($this->pages($jetons['access_token']) as $urn => $nom) {
                $comptes[] = $commun + [
                    'groupe'              => $urn,
                    'libelle'             => "{$nom}  ·  Page entreprise",
                    'external_account_id' => $urn,
                    'account_name'        => $nom,
                    'settings'            => ['auteur' => $urn, 'type' => 'organisation'],
                ];
            }
        }

        if (! $comptes) {
            throw new \RuntimeException(in_array('w_organization_social', $accordees, true)
                ? 'Ce compte LinkedIn n’administre aucune Page entreprise (rôle Administrateur ou Administrateur de contenu requis).'
                : 'LinkedIn n’a pas accordé la permission de publier (w_member_social). Vérifiez les produits de l’application LinkedIn.');
        }

        return $comptes;
    }

    public function rafraichirSiBesoin(Integration $integration): void
    {
        if ($integration->token_expires_at && $integration->token_expires_at->gt(now()->addMinutes(5))) {
            return;
        }

        // Sans jeton de renouvellement (application non approuvée), seul un
        // nouveau branchement prolonge l'accès.
        if (! $integration->refresh_token) {
            throw $this->aRebrancher($integration);
        }

        $jetons = $this->demanderJetons([
            'grant_type'    => 'refresh_token',
            'refresh_token' => $integration->refresh_token,
        ]) ?? throw $this->aRebrancher($integration);

        $integration->update(array_filter([
            'access_token'     => $jetons['access_token'],
            'token_expires_at' => now()->addSeconds((int) ($jetons['expires_in'] ?? 60 * 86400)),
            'refresh_token'    => $jetons['refresh_token'] ?? null,
        ]));
    }

    // =====================================================================
    // Plomberie
    // =====================================================================

    /**
     * Les Pages où la personne peut publier : urn => nom.
     */
    private function pages(string $jeton): array
    {
        $pages = [];
        $debut = 0;

        do {
            $reponse = $this->api->requete($jeton, 20)->get(ClientLinkedin::BASE . '/organizationAcls', [
                'q'     => 'roleAssignee',
                'state' => 'APPROVED',
                'start' => $debut,
                'count' => 100,
            ]);

            if ($reponse->failed()) {
                throw new \RuntimeException('LinkedIn a refusé la lecture des Pages administrées : ' . $this->api->motif($reponse));
            }

            $elements = $reponse->json('elements', []);

            foreach ($elements as $acl) {
                // Selon la version de l'API, la Page est sous « organization » ou « organizationTarget ».
                $urn = $acl['organization'] ?? $acl['organizationTarget'] ?? null;

                if ($urn && in_array($acl['role'] ?? '', self::ROLES_EDITEURS, true)) {
                    $pages[$urn] = null;
                }
            }

            $debut += 100;
        } while (count($elements) === 100);

        foreach (array_keys($pages) as $urn) {
            $id    = substr($urn, strrpos($urn, ':') + 1);
            $page  = $this->api->requete($jeton, 20)->get(ClientLinkedin::BASE . '/organizations/' . $id);
            $pages[$urn] = $page->json('localizedName') ?: "Page {$id}";
        }

        return $pages;
    }

    /** LinkedIn sépare les permissions accordées par des virgules ou des espaces, selon les cas. */
    private function permissions(string $liste): array
    {
        return array_values(array_filter(preg_split('/[\s,]+/', $liste)));
    }

    /** Retourne null si le code ou le jeton de renouvellement est refusé ; lève une exception pour tout autre refus. */
    private function demanderJetons(array $params): ?array
    {
        $reponse = Http::timeout(20)->asForm()->post(self::OAUTH . '/accessToken', $params + [
            'client_id'     => config('services.linkedin.client_id'),
            'client_secret' => config('services.linkedin.client_secret'),
        ]);

        $corps = $reponse->json() ?? [];

        // invalid_grant (code) ou invalid_request (jeton de renouvellement
        // expiré ou révoqué) : rien ne se réparera sans un nouveau branchement.
        if (in_array($corps['error'] ?? null, ['invalid_grant', 'invalid_request'], true)) {
            return null;
        }

        if ($reponse->failed() || empty($corps['access_token'])) {
            throw new \RuntimeException('LinkedIn a refusé l’échange de jetons : '
                . ($corps['error_description'] ?? $corps['error'] ?? "HTTP {$reponse->status()}"));
        }

        return $corps;
    }

    private function aRebrancher(Integration $integration): \RuntimeException
    {
        $message = 'Le compte LinkedIn doit être rebranché (accès expiré ou révoqué).';

        $integration->update(['last_error' => $message]);

        return new \RuntimeException($message);
    }
}
