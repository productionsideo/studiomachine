<?php

use Illuminate\Support\Facades\Schedule;

/*
 * Le cron du compte cPanel n'appelle plus qu'une chose, chaque minute :
 *   php artisan schedule:run
 * C'est ici, et seulement ici, que se décide ce qui tourne et quand.
 */

// Publications programmées : chaque minute, une seule passe à la fois.
Schedule::command('publications:envoyer')
    ->everyMinute()
    ->withoutOverlapping(10)
    ->appendOutputTo(storage_path('logs/publication.log'));

// Commentaires Facebook / Instagram : comme avant, toutes les 15 minutes.
Schedule::command('social:synchroniser')
    ->everyFifteenMinutes()
    ->withoutOverlapping(30)
    ->appendOutputTo(storage_path('logs/social.log'));

// Google Analytics 4 : toutes les 3 heures, les 3 derniers jours (GA4
// corrige ses chiffres pendant environ 48 h).
Schedule::command('ga4:synchroniser')
    ->everyThreeHours()
    ->withoutOverlapping(60)
    ->appendOutputTo(storage_path('logs/ga4.log'));

// Copies locales de vidéos R2 (envoyées à YouTube ou TikTok) : effacées
// après un jour.
Schedule::call(fn () => \App\Services\Mediatheque::nettoyerCache())
    ->daily()
    ->name('medias:nettoyer-cache');
