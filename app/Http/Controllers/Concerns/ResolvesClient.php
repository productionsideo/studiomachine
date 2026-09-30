<?php

namespace App\Http\Controllers\Concerns;

use App\Models\Client;
use Illuminate\Http\Request;

/**
 * Détermine sur quel client porte la page consultée.
 *
 * C'est le point unique de cloisonnement des données : un utilisateur client
 * est toujours ramené à SON client, quoi qu'il mette dans l'URL. Seul un
 * admin peut choisir, ou ne rien choisir (vue d'ensemble tous clients).
 */
trait ResolvesClient
{
    protected function resolveClient(Request $request): ?Client
    {
        $user = $request->user();

        if (! $user->isAdmin()) {
            return $user->client;
        }

        $slug = $request->query('client');

        return $slug ? Client::where('slug', $slug)->first() : null;
    }

    /** Interdit à un utilisateur d'accéder aux données d'un autre client. */
    protected function authorizeClient(Request $request, int $clientId): void
    {
        abort_unless($request->user()->canAccessClient($clientId), 403);
    }

    /** La période affichée, en jours (30 par défaut). */
    protected function resolveDays(Request $request): int
    {
        $days = (int) $request->query('jours', 30);

        return in_array($days, [7, 30, 90, 365], true) ? $days : 30;
    }
}
