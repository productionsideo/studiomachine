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

];
