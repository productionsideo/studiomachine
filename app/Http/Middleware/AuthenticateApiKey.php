<?php

namespace App\Http\Middleware;

use App\Models\Client;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authentifie un site client par sa clé API (en-tête X-API-Key).
 *
 * Le client authentifié est déposé sur la requête ; les contrôleurs
 * d'ingestion s'en servent comme unique source du client_id. Ils ne
 * doivent jamais lire un client_id envoyé dans le corps de la requête,
 * sinon n'importe quel client pourrait écrire dans les données d'un autre.
 */
class AuthenticateApiKey
{
    public function handle(Request $request, Closure $next): Response
    {
        $key = $request->header('X-API-Key')
            ?? $request->bearerToken();

        $client = Client::findByApiKey($key);

        if (! $client) {
            return response()->json([
                'ok'    => false,
                'error' => 'cle_api_invalide',
            ], 401);
        }

        $request->attributes->set('client', $client);

        return $next($request);
    }
}
