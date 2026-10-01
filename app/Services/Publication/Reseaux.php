<?php

namespace App\Services\Publication;

/**
 * Le catalogue des réseaux publiables : formats, limites de texte, et la
 * classe qui sait y publier.
 *
 * Les limites servent à l'éditeur (compteurs) ET à la vérification avant
 * envoi : un texte trop long refusé par le réseau à 9 h du matin, c'est une
 * publication ratée que personne ne voit avant midi.
 */
final class Reseaux
{
    public const CATALOGUE = [
        'facebook' => [
            'nom'      => 'Facebook',
            'publieur' => PublieurFacebook::class,
            'formats'  => ['reel' => 'Reel', 'post' => 'Publication'],
            'texte_max'=> 63206,
        ],
        'instagram' => [
            'nom'      => 'Instagram',
            'publieur' => PublieurInstagram::class,
            'formats'  => ['reel' => 'Reel', 'image' => 'Image', 'carrousel' => 'Carrousel', 'story' => 'Story'],
            'texte_max'=> 2200,
        ],
        'youtube' => [
            'nom'      => 'YouTube',
            'publieur' => PublieurYoutube::class,
            'formats'  => ['short' => 'Short', 'video' => 'Vidéo'],
            'texte_max'=> 5000,
            'titre_max'=> 100,
        ],
        'tiktok' => [
            'nom'      => 'TikTok',
            'publieur' => PublieurTiktok::class,
            'formats'  => ['video' => 'Vidéo'],
            'texte_max'=> 2200,
        ],
    ];

    public static function publieur(string $plateforme): Publieur
    {
        $classe = self::CATALOGUE[$plateforme]['publieur']
            ?? throw new \InvalidArgumentException("Réseau inconnu : {$plateforme}");

        return app($classe);
    }

    public static function nom(string $plateforme): string
    {
        return self::CATALOGUE[$plateforme]['nom'] ?? ucfirst($plateforme);
    }
}
