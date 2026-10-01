<?php

namespace App\Services;

use App\Models\Integration;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Copie les chiffres GA4 d'un client dans ga4_trafic et ga4_evenements.
 *
 * GA4 corrige ses chiffres pendant environ 48 h (sessions tardives, achats
 * rattachés après coup) : on repasse donc toujours sur les derniers jours,
 * et on REMPLACE la période plutôt que d'additionner — une ligne que Google a
 * fusionnée ou supprimée doit disparaître chez nous aussi.
 */
class Ga4Collecte
{
    public function __construct(private Ga4 $ga4) {}

    /** Retourne [lignes de trafic, lignes d'événements]. Lève une exception lisible en cas de refus. */
    public function collecter(Integration $integration, int $jours = 3): array
    {
        $debut = now(config('publication.fuseau'))->subDays($jours - 1)->toDateString();
        $fin   = now(config('publication.fuseau'))->toDateString();
        $plage = [['startDate' => $debut, 'endDate' => $fin]];

        try {
            $trafic = $this->ga4->rapport($integration, [
                'dateRanges' => $plage,
                'dimensions' => array_map(fn ($n) => ['name' => $n], ['date', 'sessionSource', 'sessionMedium', 'sessionCampaignName']),
                'metrics'    => array_map(fn ($n) => ['name' => $n], ['sessions', 'totalUsers', 'engagedSessions', 'keyEvents', 'ecommercePurchases', 'purchaseRevenue']),
            ]);

            // Les clics sortants vers Amazon : la mesure améliorée de GA4 les
            // enregistre comme événement « click » avec le domaine du lien.
            // Les boutons d'achat passent souvent par les liens courts
            // d'Amazon (a.co, amzn.to) : un filtre sur « amazon » seul les
            // manquait tous — c'était le cas de bj21rules.com.
            $domaine = fn (string $type, string $valeur) => ['filter' => ['fieldName' => 'linkDomain', 'stringFilter' => ['matchType' => $type, 'value' => $valeur, 'caseSensitive' => false]]];

            $amazon = $this->ga4->rapport($integration, [
                'dateRanges' => $plage,
                'dimensions' => array_map(fn ($n) => ['name' => $n], ['date', 'sessionCampaignName', 'sessionSource']),
                'metrics'    => [['name' => 'eventCount']],
                'dimensionFilter' => ['andGroup' => ['expressions' => [
                    ['filter' => ['fieldName' => 'eventName', 'stringFilter' => ['matchType' => 'EXACT', 'value' => 'click']]],
                    ['orGroup' => ['expressions' => [
                        $domaine('CONTAINS', 'amazon'),
                        $domaine('CONTAINS', 'amzn'),
                        $domaine('EXACT', 'a.co'),
                    ]]],
                ]]],
            ]);
        } catch (\Throwable $e) {
            $integration->update(['last_error' => mb_substr($e->getMessage(), 0, 500)]);

            throw $e;
        }

        $client = $integration->client_id;
        $jour   = fn ($d) => Carbon::createFromFormat('Ymd', $d)->toDateString();
        $court  = fn ($s) => mb_substr($s === '' ? '(not set)' : $s, 0, 150);

        DB::transaction(function () use ($client, $debut, $fin, $trafic, $amazon, $jour, $court) {
            DB::table('ga4_trafic')->where('client_id', $client)->whereBetween('jour', [$debut, $fin])->delete();
            DB::table('ga4_evenements')->where('client_id', $client)->whereBetween('jour', [$debut, $fin])->delete();

            $maintenant = now();

            foreach (array_chunk($trafic, 500) as $paquet) {
                DB::table('ga4_trafic')->insert(array_map(fn ($l) => [
                    'client_id'         => $client,
                    'jour'              => $jour($l['date']),
                    'source'            => $court($l['sessionSource']),
                    'support'           => $court($l['sessionMedium']),
                    'campagne'          => $court($l['sessionCampaignName']),
                    'sessions'          => (int) $l['sessions'],
                    'utilisateurs'      => (int) $l['totalUsers'],
                    'sessions_engagees' => (int) $l['engagedSessions'],
                    'evenements_cles'   => (int) round((float) $l['keyEvents']),
                    'achats'            => (int) $l['ecommercePurchases'],
                    'revenus'           => round((float) $l['purchaseRevenue'], 2),
                    'created_at'        => $maintenant,
                    'updated_at'        => $maintenant,
                ], $paquet));
            }

            foreach (array_chunk($amazon, 500) as $paquet) {
                DB::table('ga4_evenements')->insert(array_map(fn ($l) => [
                    'client_id'  => $client,
                    'jour'       => $jour($l['date']),
                    'evenement'  => 'clic_amazon',
                    'campagne'   => $court($l['sessionCampaignName']),
                    'source'     => $court($l['sessionSource']),
                    'nombre'     => (int) $l['eventCount'],
                    'created_at' => $maintenant,
                    'updated_at' => $maintenant,
                ], $paquet));
            }
        });

        $integration->update(['last_synced_at' => now(), 'last_error' => null]);

        return [count($trafic), count($amazon)];
    }
}
