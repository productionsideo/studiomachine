<?php

namespace App\Console\Commands;

use App\Models\Client;
use Illuminate\Console\Command;

/**
 * Émet une nouvelle clé API pour un client et l'écrit seule sur la sortie,
 * pour qu'un script la passe directement au site du client sans qu'elle
 * s'affiche (deploy/brancher-bj21.sh).
 *
 * L'ancienne clé cesse aussitôt de fonctionner : à ne lancer que pour un site
 * qui n'envoie encore rien, ou qu'on reconfigure dans la foulée.
 */
class EmettreCleApi extends Command
{
    protected $signature = 'clients:emettre-cle {slug : Le client (son slug)}';

    protected $description = 'Émet une nouvelle clé API pour un client (remplace l’ancienne)';

    public function handle(): int
    {
        $client = Client::where('slug', $this->argument('slug'))->first();

        if (! $client) {
            $this->error("Aucun client « {$this->argument('slug')} ».");

            return self::FAILURE;
        }

        $this->line($client->generateApiKey());

        return self::SUCCESS;
    }
}
