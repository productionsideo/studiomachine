<?php

use Illuminate\Foundation\Application;
use Illuminate\Http\Request;

define('LARAVEL_START', microtime(true));

/**
 * Contrôleur frontal du dashboard.
 *
 * L'application vit hors de public_html : son code, son .env (qui contient
 * le mot de passe de la base et les jetons des réseaux sociaux) et ses
 * journaux ne peuvent donc jamais être servis par le serveur web, même si
 * la réécriture venait à tomber.
 */
$base = dirname(__DIR__, 2) . '/gestion-app';

if (file_exists($maintenance = $base . '/storage/framework/maintenance.php')) {
    require $maintenance;
}

require $base . '/vendor/autoload.php';

/** @var Application $app */
$app = require_once $base . '/bootstrap/app.php';

$app->handleRequest(Request::capture());
