<?php

/**
 * Planification et publication sur les réseaux sociaux.
 */
return [

    // Les dates sont stockées en UTC et affichées dans ce fuseau. L'équipe et
    // les clients sont au Québec : « 9 h » veut dire 9 h à Montréal.
    'fuseau' => env('PUBLICATION_FUSEAU', 'America/Toronto'),

    // Où vivent les médias, et l'URL publique qui les sert. Instagram et TikTok
    // téléchargent la vidéo eux-mêmes à partir de cette URL : elle doit être
    // joignable depuis Internet, sans session. En production, le dossier
    // public_html/gestion/fichiers est un lien vers storage/app/medias.
    'medias' => [
        'dossier'      => storage_path('app/medias'),
        'temporaire'   => storage_path('app/medias-envois'),
        'url'          => env('MEDIAS_URL', rtrim(env('APP_URL', ''), '/') . '/fichiers'),

        // Le serveur refuse les envois de plus de 25 Mo par requête : les
        // fichiers partent donc en morceaux, recollés ici. Taille maximale
        // d'un morceau ; Mediatheque::tailleMorceau() la réduit au besoin
        // pour tenir sous les limites du PHP qui sert la page.
        'morceau_octets' => 5 * 1024 * 1024,
        'max_octets'     => 1024 * 1024 * 1024,   // 1 Go

        'types' => [
            'image/jpeg' => ['image', 'jpg'],
            'image/png'  => ['image', 'png'],
            'image/webp' => ['image', 'webp'],
            'video/mp4'  => ['video', 'mp4'],
            'video/quicktime' => ['video', 'mov'],
        ],

        'ffmpeg'  => env('FFMPEG_BIN', 'ffmpeg'),
        'ffprobe' => env('FFPROBE_BIN', 'ffprobe'),

        // Petite copie locale d'un média stocké sur R2, le temps de l'envoyer
        // à YouTube ou TikTok (qui reçoivent le fichier, pas une adresse).
        'cache' => storage_path('app/medias-cache'),

        // Cloudflare R2. Renseigné, il reçoit tous les nouveaux médias : les
        // vidéos y vont directement depuis le navigateur, sans passer par le
        // serveur (ni sa limite de 25 Mo par requête, ni ffprobe). Vide, tout
        // reste sur le disque du serveur comme avant.
        'r2' => [
            'compte'      => env('R2_ACCOUNT_ID'),
            'cle'         => env('R2_ACCESS_KEY_ID'),
            'secret'      => env('R2_SECRET_ACCESS_KEY'),
            'bucket'      => env('R2_BUCKET'),
            'url'         => env('R2_PUBLIC_URL'),         // ex. https://medias.studiomachine.ca
            'part_octets' => 10 * 1024 * 1024,             // R2 : 5 Mo minimum, toutes égales sauf la dernière
        ],
    ],

    // Un échec passager (réseau, limite de débit) est retenté ; un refus
    // définitif (permission, format) ne l'est jamais.
    'essais_max'          => 4,
    'delai_essai_minutes' => [2, 10, 30],

    // Pendant qu'une plateforme traite une vidéo, on repasse voir toutes les…
    'delai_traitement_secondes' => 60,

    // … et on abandonne au bout de :
    'traitement_max_minutes' => 120,
];
