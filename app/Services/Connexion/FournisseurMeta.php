<?php

namespace App\Services\Connexion;

use App\Models\Integration;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

/**
 * Branchement d'une Page Facebook et de son compte Instagram professionnel
 * par Facebook Login, en remplacement du jeton collé depuis l'explorateur
 * (commande meta:brancher, qui reste disponible en secours).
 *
 * On garde le JETON DE PAGE, jamais le jeton d'utilisateur : obtenu à partir
 * d'un jeton d'utilisateur long, il n'expire pas. Voir BrancherMeta pour
 * l'explication complète des trois jetons de Meta.
 */
class FournisseurMeta implements FournisseurOAuth
{
    /**
     * Les permissions demandées. Chacune devra être approuvée par Meta (accès
     * avancé) avant de servir sur les comptes d'un client.
     */
    public const PERMISSIONS = [
        'pages_show_list',
        'business_management',          // Pages détenues par un portefeuille Business
        'pages_read_engagement',
        'pages_read_user_content',      // lire les commentaires
        'pages_manage_engagement',      // y répondre
        'pages_manage_posts',           // publier
        'publish_video',                // publier une vidéo ou un Reel
        'read_insights',
        'instagram_basic',
        'instagram_content_publish',
        'instagram_manage_comments',
        'instagram_manage_insights',
    ];

    private function graphe(): string
    {
        return 'https://graph.facebook.com/' . config('meta.version');
    }

    public function urlAutorisation(string $etat, string $retour): string
    {
        $params = [
            'client_id'     => config('services.meta.client_id'),
            'redirect_uri'  => $retour,
            'state'         => $etat,
            'response_type' => 'code',
        ];

        // Facebook Login for Business se configure dans le tableau de bord de
        // l'app (une « configuration » qui porte les permissions). Si elle
        // existe, c'est elle qui fait foi ; sinon on liste les permissions.
        if ($config = config('meta.login_config_id')) {
            $params['config_id'] = $config;
        } else {
            $params['scope'] = implode(',', self::PERMISSIONS);
        }

        return 'https://www.facebook.com/' . config('meta.version') . '/dialog/oauth?' . http_build_query($params);
    }

    public function recevoir(Request $request, string $retour): array
    {
        if ($request->filled('error')) {
            throw new \RuntimeException('Connexion annulée sur Facebook : ' . $request->input('error_description', $request->input('error')));
        }

        $court = $this->jeton([
            'client_id'     => config('services.meta.client_id'),
            'client_secret' => config('services.meta.client_secret'),
            'redirect_uri'  => $retour,
            'code'          => (string) $request->input('code'),
        ]);

        $long = $this->jeton([
            'grant_type'        => 'fb_exchange_token',
            'client_id'         => config('services.meta.client_id'),
            'client_secret'     => config('services.meta.client_secret'),
            'fb_exchange_token' => $court,
        ]);

        $pages = $this->pages($long);

        if ($pages === []) {
            throw new \RuntimeException(
                'Ce compte Facebook ne donne accès à aucune Page. Vérifiez qu’il a un rôle sur la Page '
                . '(ou dans le portefeuille Business qui la détient) et que la permission '
                . '« business_management » a été accordée à la connexion.'
            );
        }

        $comptes = [];

        foreach ($pages as $page) {
            if (empty($page['access_token'])) {
                continue;
            }

            $ig = $page['instagram_business_account'] ?? null;

            $libelle = $page['name'] . (! empty($ig['username']) ? '  ·  Instagram @' . $ig['username'] : '  ·  sans Instagram');

            $comptes[] = [
                'groupe'              => $page['id'],
                'libelle'             => $libelle,
                'platform'            => 'facebook',
                'external_account_id' => $page['id'],
                'account_name'        => $page['name'],
                'access_token'        => $page['access_token'],
                'refresh_token'       => null,
                'token_expires_at'    => null,     // un jeton de Page n'expire pas
                'scopes'              => null,
                'settings'            => ['page_id' => $page['id']],
            ];

            if ($ig) {
                $comptes[] = [
                    'groupe'              => $page['id'],
                    'libelle'             => $libelle,
                    'platform'            => 'instagram',
                    'external_account_id' => $ig['id'],
                    'account_name'        => isset($ig['username']) ? '@' . $ig['username'] : $page['name'],
                    // Instagram passe par la Page : même jeton.
                    'access_token'        => $page['access_token'],
                    'refresh_token'       => null,
                    'token_expires_at'    => null,
                    'scopes'              => null,
                    'settings'            => ['ig_user_id' => $ig['id'], 'page_id' => $page['id']],
                ];
            }
        }

        return $comptes;
    }

    public function rafraichirSiBesoin(Integration $integration): void
    {
        // Rien à faire : le jeton de Page n'expire pas. Il ne cesse de
        // fonctionner que si la personne perd son rôle sur la Page ou change
        // son mot de passe — et alors seul un nouveau branchement y remédie.
    }

    private function jeton(array $params): string
    {
        $reponse = Http::timeout(20)->get($this->graphe() . '/oauth/access_token', $params)->json();

        if (empty($reponse['access_token'])) {
            throw new \RuntimeException('Meta a refusé l’échange de jeton : ' . ($reponse['error']['message'] ?? 'motif inconnu'));
        }

        return $reponse['access_token'];
    }

    /**
     * Les Pages accessibles : d'abord celles administrées en propre, puis
     * celles des portefeuilles Business (le cas courant d'une agence).
     */
    private function pages(string $jetonLong): array
    {
        $champs = 'id,name,access_token,instagram_business_account{id,username}';

        $pages = Http::timeout(20)->get($this->graphe() . '/me/accounts', [
            'fields' => $champs, 'limit' => 100, 'access_token' => $jetonLong,
        ])->json('data') ?? [];

        $connues = array_column($pages, 'id');

        $portefeuilles = Http::timeout(20)->get($this->graphe() . '/me/businesses', [
            'fields' => 'id', 'access_token' => $jetonLong,
        ])->json('data') ?? [];

        foreach ($portefeuilles as $business) {
            foreach (['owned_pages', 'client_pages'] as $lien) {
                $liste = Http::timeout(20)->get($this->graphe() . "/{$business['id']}/{$lien}", [
                    'fields' => 'id', 'limit' => 100, 'access_token' => $jetonLong,
                ])->json('data') ?? [];

                foreach ($liste as $p) {
                    if (in_array($p['id'], $connues, true)) {
                        continue;
                    }

                    // Le jeton de Page n'est pas donné sur ces liens : il faut
                    // le demander à la Page elle-même.
                    $detail = Http::timeout(20)->get($this->graphe() . "/{$p['id']}", [
                        'fields' => $champs, 'access_token' => $jetonLong,
                    ])->json();

                    if (! empty($detail['access_token'])) {
                        $pages[]   = $detail;
                        $connues[] = $p['id'];
                    }
                }
            }
        }

        return $pages;
    }
}
