<?php

use App\Http\Controllers\Api\IngestController;
use Illuminate\Support\Facades\Route;

/**
 * Ingestion des données des sites clients.
 *
 * Authentification par clé API (en-tête X-API-Key) plutôt que par session :
 * l'émetteur est un serveur, pas un navigateur. Cela permet aussi de brancher
 * un futur client hébergé ailleurs que sur ce serveur.
 *
 * Débit limité pour qu'une boucle défaillante sur un site client ne puisse
 * pas saturer la base de tous les autres.
 */
Route::prefix('v1')->middleware(['apikey', 'throttle:120,1'])->group(function () {
    Route::get('/ping',    [IngestController::class, 'ping']);
    Route::post('/events', [IngestController::class, 'events']);
    Route::post('/leads',  [IngestController::class, 'lead']);
});
