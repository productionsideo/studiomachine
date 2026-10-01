<?php

/**
 * Accès à l'API Graph de Meta (Facebook et Instagram).
 *
 * L'identifiant et le secret de l'application ne servent PAS à lire les
 * commentaires — c'est le jeton de Page, stocké chiffré dans `integrations`,
 * qui fait ce travail. Ils servent à deux choses seulement :
 *
 *   1. échanger un jeton court (celui que Meta remet dans l'explorateur, valide
 *      une heure) contre un jeton long (60 jours) ;
 *   2. interroger /debug_token pour savoir quand un jeton expire et quelles
 *      permissions il porte réellement — plutôt que de le supposer.
 *
 * Sans eux, le connecteur fonctionne quand même : il ne saura simplement pas
 * prolonger un jeton ni diagnostiquer son expiration.
 */
return [

    'version' => env('META_GRAPH_VERSION', 'v25.0'),

    'app_id'     => env('META_APP_ID'),
    'app_secret' => env('META_APP_SECRET'),

    // Facebook Login for Business : identifiant de la « configuration » créée
    // dans le tableau de bord de l'app. Facultatif ; sans lui, on liste les
    // permissions dans la demande.
    'login_config_id' => env('META_LOGIN_CONFIG_ID'),

    // Combien de publications on remonte à chaque synchronisation. Les capsules
    // sont publiées au fil des semaines ; il est inutile de repasser sur les
    // 72 à chaque fois. Les commentaires arrivent surtout sur le récent.
    'publications_par_passe' => 25,

    // Un commentaire vieux de plus de ça n'est plus rapatrié. Répondre trois
    // mois plus tard ne sert personne, et ça évite de repasser éternellement
    // sur le même historique.
    'anciennete_max_jours' => 60,

    // Meta limite le débit par application. On reste loin du plafond : la
    // synchronisation n'est pas pressée, et se faire bloquer pour une heure
    // coûte plus cher que d'attendre trois minutes.
    'delai_entre_appels_ms' => 120,
];
