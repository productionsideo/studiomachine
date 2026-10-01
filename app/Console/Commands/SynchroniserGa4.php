<?php

namespace App\Console\Commands;

use App\Models\Integration;
use App\Services\Ga4Collecte;
use Illuminate\Console\Command;

/**
 * Collecte GA4 de tous les clients branchés.
 *
 * Planifiée toutes les 3 heures sur les 3 derniers jours (routes/console.php).
 * Au premier branchement, lancer une fois --jours=90 pour l'historique.
 */
class SynchroniserGa4 extends Command
{
    protected $signature = 'ga4:synchroniser
                            {--client= : Ne traiter qu\'un client (son id)}
                            {--jours=3 : Nombre de jours à (re)collecter, jusqu\'à aujourd\'hui}';

    protected $description = 'Copie les chiffres Google Analytics 4 des clients dans la base';

    public function handle(Ga4Collecte $collecte): int
    {
        $integrations = Integration::where('platform', 'ga4')
            ->where('active', true)
            ->whereNotNull('access_token')
            ->when($this->option('client'), fn ($q, $c) => $q->where('client_id', $c))
            ->with('client')
            ->get();

        $jours = max(1, min(400, (int) $this->option('jours')));

        foreach ($integrations as $integration) {
            try {
                [$trafic, $amazon] = $collecte->collecter($integration, $jours);
                $this->line("  ✓ {$integration->client->name} : {$trafic} ligne(s) de trafic, {$amazon} de clics Amazon ({$jours} j)");
            } catch (\Throwable $e) {
                // Un client mal branché ne bloque pas les autres.
                $this->error("  ✗ {$integration->client->name} : {$e->getMessage()}");
            }
        }

        if ($integrations->isEmpty()) {
            $this->line('Aucune propriété GA4 branchée avec une clé.');
        }

        return self::SUCCESS;
    }
}
