<?php

/*
|--------------------------------------------------------------------------
| Assistant Claude
|--------------------------------------------------------------------------
|
| Claude lit chaque commentaire reçu sur les réseaux, le classe, et rédige
| une réponse. Ce qu'il ne fait PAS : décider seul de l'envoyer. Cette
| décision est prise par le code (voir AssistantClaude::peutPartirSeul),
| jamais par le modèle — une politique de publication ne se délègue pas à
| ce qu'on cherche justement à encadrer.
|
| Sans clé, l'assistant est simplement inactif : la boîte de réception
| fonctionne, sans suggestion. Rien ne casse.
|
*/

return [

    // ANTHROPIC_API_KEY dans .env — format sk-ant-…
    'cle' => env('ANTHROPIC_API_KEY'),

    'modele' => env('ANTHROPIC_MODEL', 'claude-opus-4-8'),

    // Un commentaire de réseau social est court : la réponse l'est aussi.
    'jetons_max' => 2000,

    // « medium » : assez pour peser un sous-entendu, pas assez pour délibérer
    // pendant dix secondes sur un « bravo ! ». La page attend la réponse.
    'effort' => 'medium',

    /*
    | Les sujets qui exigent une validation humaine, quoi qu'en pense Claude.
    |
    | CRD vend des maisons à plusieurs centaines de milliers de dollars. Un
    | prix ou une date de livraison annoncés de travers dans un commentaire
    | public ne sont pas un bogue : c'est un engagement commercial. Dès qu'un
    | de ces sujets est détecté, la réponse reste en brouillon.
    */
    'sujets_sensibles' => [
        'prix', 'delais', 'disponibilite', 'terrain', 'financement',
        'technique', 'plainte', 'rendez_vous',
    ],

];
