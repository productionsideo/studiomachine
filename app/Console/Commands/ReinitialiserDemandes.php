<?php

namespace App\Console\Commands;

use App\Models\Capsule;
use App\Models\Client;
use App\Models\Event;
use App\Models\Lead;
use Illuminate\Console\Command;

/**
 * Efface les demandes et le suivi de parcours accumulés pendant les essais.
 *
 * Sert une seule fois, juste avant le lancement d'une campagne : les demandes
 * de test et les visites qui les ont produites fausseraient le taux de
 * conversion dès le premier jour.
 *
 * Pourquoi effacer AUSSI les événements, et pas seulement les demandes : les
 * deux viennent des mêmes essais. Ne garder que les événements donnerait un
 * tableau de bord montrant des visites et des abandons sans demandes
 * correspondantes — un taux de conversion faussement catastrophique.
 *
 * Les capsules, elles, ne partent QUE si on le demande (--capsules). Elles se
 * recréent d'elles-mêmes au premier clic, mais si quelqu'un en a renommé une à
 * la main, ce titre serait perdu.
 *
 * Rien ici n'est réversible. D'où le décompte affiché avant toute écriture, et
 * la confirmation obligatoire.
 */
class ReinitialiserDemandes extends Command
{
    protected $signature = 'demandes:reinitialiser
                            {--client= : Ne traiter qu\'un client (son id)}
                            {--capsules : Effacer aussi les capsules créées automatiquement}
                            {--force : Ne pas demander confirmation}';

    protected $description = 'Efface les demandes et le suivi de parcours des essais. IRRÉVERSIBLE.';

    public function handle(): int
    {
        $client = null;

        if ($id = $this->option('client')) {
            $client = Client::find($id);

            if (! $client) {
                $this->error("Aucun client avec l'id {$id}.");

                return self::FAILURE;
            }
        }

        $portee = fn ($q) => $client ? $q->where('client_id', $client->id) : $q;

        $demandes  = $portee(Lead::query())->count();
        $evenement = $portee(Event::query())->count();
        $capsules  = $portee(Capsule::query())->count();

        $qui = $client ? $client->name : 'TOUS les clients';

        $this->newLine();
        $this->line("Portée : <options=bold>{$qui}</>");
        $this->newLine();

        $this->table(['Table', 'À effacer'], [
            ['Demandes (leads)', $demandes],
            ['Suivi de parcours (events)', $evenement],
            ['Capsules', $this->option('capsules') ? $capsules : "{$capsules} — conservées"],
        ]);

        if ($demandes === 0 && $evenement === 0) {
            $this->info('Rien à effacer.');

            return self::SUCCESS;
        }

        // La plus récente et la plus ancienne : de quoi voir d'un coup d'œil
        // qu'on n'efface pas autre chose que les essais qu'on croit viser.
        if ($demandes > 0) {
            $this->line('Demandes du '
                . $portee(Lead::query())->min('submitted_at') . ' au '
                . $portee(Lead::query())->max('submitted_at') . '.');
            $this->newLine();
        }

        $this->warn('Cette opération est IRRÉVERSIBLE.');

        if (! $this->option('force') && ! $this->confirm('Effacer ?', false)) {
            $this->line('Rien n’a été modifié.');

            return self::SUCCESS;
        }

        // L'ordre n'a pas d'importance pour l'intégrité — capsule_id est en
        // nullOnDelete des deux côtés — mais on efface les dépendants d'abord,
        // par principe.
        $portee(Lead::query())->delete();
        $portee(Event::query())->delete();

        $this->info("{$demandes} demande(s) et {$evenement} événement(s) effacés.");

        if ($this->option('capsules')) {
            $portee(Capsule::query())->delete();
            $this->info("{$capsules} capsule(s) effacée(s). Elles se recréeront au premier clic.");
        }

        return self::SUCCESS;
    }
}
