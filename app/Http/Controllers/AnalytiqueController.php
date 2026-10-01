<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ResolvesClient;
use App\Models\Client;
use App\Models\Integration;
use App\Services\Ga4Collecte;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Google Analytics 4 d'un client : trafic par provenance, campagnes,
 * achats et clics vers Amazon. Ne lit que la copie locale (ga4_trafic).
 */
class AnalytiqueController extends Controller
{
    use ResolvesClient;

    public function index(Request $request)
    {
        $client = $this->resolveClient($request);

        if (! $client) {
            return view('publication.choisir-client', [
                'clients' => Client::where('active', true)->orderBy('name')->get(),
                'suite'   => 'analytique.index',
            ]);
        }

        $this->authorizeClient($request, $client->id);

        $jours = $this->resolveDays($request);
        $depuis = now(config('publication.fuseau'))->subDays($jours - 1)->toDateString();
        $ga4 = $client->integrations()->where('platform', 'ga4')->first();

        $trafic = DB::table('ga4_trafic')->where('client_id', $client->id)->where('jour', '>=', $depuis);
        $clics  = DB::table('ga4_evenements')->where('client_id', $client->id)->where('jour', '>=', $depuis)->where('evenement', 'clic_amazon');

        $totaux = (clone $trafic)->selectRaw('sum(sessions) s, sum(utilisateurs) u, sum(sessions_engagees) e, sum(evenements_cles) k, sum(achats) a, sum(revenus) r')->first();

        $parJour = (clone $trafic)->selectRaw('jour, sum(sessions) s, sum(achats) a')->groupBy('jour')->orderBy('jour')->get()->keyBy('jour');

        $clicsParSource = (clone $clics)->selectRaw('source, sum(nombre) n')->groupBy('source')->pluck('n', 'source');
        $clicsParCampagne = (clone $clics)->selectRaw('campagne, sum(nombre) n')->groupBy('campagne')->pluck('n', 'campagne');

        $parSource = (clone $trafic)
            ->selectRaw('source, support, sum(sessions) s, sum(sessions_engagees) e, sum(evenements_cles) k, sum(achats) a, sum(revenus) r')
            ->groupBy('source', 'support')->orderByDesc('s')->limit(20)->get();

        $parCampagne = (clone $trafic)
            ->whereNotIn('campagne', ['(not set)', '(direct)', '(organic)', '(referral)', '(none)'])
            ->selectRaw('campagne, sum(sessions) s, sum(sessions_engagees) e, sum(achats) a, sum(revenus) r')
            ->groupBy('campagne')->orderByDesc('s')->limit(20)->get();

        // Nos campagnes, pour relier un code utm à sa fiche.
        $nos = $client->campaigns()->pluck('id', 'utm_campaign');

        // Série continue, jours vides compris : un trou dans la courbe doit
        // se voir comme un zéro, pas disparaître.
        $serie = [];
        for ($j = now(config('publication.fuseau'))->subDays($jours - 1); $j->toDateString() <= now(config('publication.fuseau'))->toDateString(); $j->addDay()) {
            $d = $j->toDateString();
            $serie[$d] = (int) ($parJour[$d]->s ?? 0);
        }

        return view('dashboard.analytique', [
            'client'   => $client,
            'clients'  => $request->user()->isAdmin() ? Client::orderBy('name')->get() : collect(),
            'jours'    => $jours,
            'ga4'      => $ga4,
            'totaux'   => $totaux,
            'clics'    => (int) $clics->sum('nombre'),
            'serie'    => $serie,
            'parSource'=> $parSource,
            'parCampagne' => $parCampagne,
            'clicsParSource' => $clicsParSource,
            'clicsParCampagne' => $clicsParCampagne,
            'nos'      => $nos,
        ]);
    }

    /** « Collecter maintenant » : 90 jours au premier passage, 3 ensuite. */
    public function collecter(Request $request, Integration $integration, Ga4Collecte $collecte)
    {
        $this->authorizeClient($request, $integration->client_id);
        abort_unless($integration->platform === 'ga4', 404);

        $jours = $integration->last_synced_at ? 3 : 90;

        try {
            [$trafic, $amazon] = $collecte->collecter($integration, $jours);
        } catch (\Throwable $e) {
            return back()->with('ok', 'GA4 : ' . $e->getMessage());
        }

        return redirect()->route('analytique.index', ['client' => $integration->client->slug])
            ->with('ok', "GA4 collecté sur {$jours} jours : {$trafic} ligne(s) de trafic, {$amazon} de clics Amazon.");
    }
}
