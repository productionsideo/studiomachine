<?php

namespace Database\Seeders;

use App\Models\Client;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Amorce la plateforme : le premier client (Construction CRD) et le premier
 * accès de l'équipe Studio Machine.
 *
 * Idempotent : relancer le seeder ne duplique rien et ne réémet pas la clé
 * API d'un client qui en a déjà une.
 */
class DemarrageSeeder extends Seeder
{
    public function run(): void
    {
        // --- Le client -----------------------------------------------------
        $client = Client::firstOrNew(['slug' => 'construction-crd']);

        if (! $client->exists) {
            $client->fill([
                'name'         => 'Construction CRD',
                'website'      => 'https://maison-neuve.constructioncrd.com',
                'accent_color' => '#9A0F20',
                'active'       => true,
            ]);

            $client->api_key_prefix = Str::random(12);
            $client->api_key_hash   = '';
            $client->save();

            $cle = $client->generateApiKey();

            // Seul moment où la clé est lisible. Elle doit être copiée
            // maintenant dans la configuration du site du client.
            $this->command->warn('CLE_API_CRD=' . $cle);
        } else {
            $this->command->info('Client « Construction CRD » déjà présent — clé inchangée.');
        }

        // --- L'accès interne ------------------------------------------------
        $courriel = 'info@studiomachine.ca';

        if (! User::where('email', $courriel)->exists()) {
            $motDePasse = Str::random(18);

            User::create([
                'client_id' => null,          // NULL = équipe Studio Machine, voit tous les clients
                'name'      => 'Studio Machine',
                'email'     => $courriel,
                'password'  => $motDePasse,   // haché par le cast du modèle
                'role'      => User::ROLE_ADMIN,
                'active'    => true,
            ]);

            $this->command->warn('ADMIN_COURRIEL=' . $courriel);
            $this->command->warn('ADMIN_MOTDEPASSE=' . $motDePasse);
        } else {
            $this->command->info("Accès {$courriel} déjà présent — mot de passe inchangé.");
        }
    }
}
