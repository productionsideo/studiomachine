<?php

return [

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'resend' => [
        'key' => env('RESEND_KEY'),
    ],

    'ses' => [
        'key'    => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel'              => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Applications développeur des réseaux sociaux
    |--------------------------------------------------------------------------
    |
    | Ces identifiants appartiennent à Studio Machine, pas aux clients : une
    | seule application par plateforme dessert tous les clients, chacun
    | autorisant ensuite ses propres comptes par OAuth.
    |
    | Tant qu'une clé est absente, la page Intégrations affiche « Application
    | à créer » plutôt qu'un bouton de connexion inopérant.
    |
    */

    'google' => [
        'client_id'     => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect'      => env('APP_URL') . '/integrations/youtube/retour',
    ],

    'meta' => [
        'client_id'     => env('META_APP_ID'),
        'client_secret' => env('META_APP_SECRET'),
        'redirect'      => env('APP_URL') . '/integrations/meta/retour',
    ],

    'tiktok' => [
        'client_id'     => env('TIKTOK_CLIENT_KEY'),
        'client_secret' => env('TIKTOK_CLIENT_SECRET'),
        'redirect'      => env('APP_URL') . '/integrations/tiktok/retour',
    ],

    // LinkedIn impose deux applications (voir FournisseurLinkedin) : l'une
    // pour les Pages entreprise (Community Management API, après examen),
    // l'autre pour les profils personnels (libre-service).
    'linkedin' => [
        'client_id'     => env('LINKEDIN_PAGES_CLIENT_ID'),
        'client_secret' => env('LINKEDIN_PAGES_CLIENT_SECRET'),
        'redirect'      => env('APP_URL') . '/integrations/linkedin/retour',
        'scopes'        => env('LINKEDIN_PAGES_SCOPES', 'w_organization_social rw_organization_admin'),
        // Version de l'API (AAAAMM), valable au moins un an après sa sortie.
        'version'       => env('LINKEDIN_VERSION', '202610'),
    ],

    'linkedin_profil' => [
        'client_id'     => env('LINKEDIN_PROFIL_CLIENT_ID'),
        'client_secret' => env('LINKEDIN_PROFIL_CLIENT_SECRET'),
        'redirect'      => env('APP_URL') . '/integrations/linkedin-profil/retour',
        'scopes'        => env('LINKEDIN_PROFIL_SCOPES', 'openid profile w_member_social'),
    ],

];
