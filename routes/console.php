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
