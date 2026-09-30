<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ResolvesClient;
use App\Models\Client;
use App\Models\Event;
use App\Models\Lead;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    use ResolvesClient;

    public function index(Request $request)
    {
        $client = $this->resolveClient($request);
        $days   = $this->resolveDays($request);
        $since  = now()->subDays($days)->startOfDay();

        // Un admin sans client choisi voit le portrait de tout le portefeuille.
        if (! $client) {
            return view('dashboard.overview', [
                'clients' => $this->portfolio($since),
                'days'    => $days,
                'totals'  => $this->portfolioTotals($since),
            ]);
        }

        $this->authorizeClient($request, $client->id);

        return view('dashboard.client', [
            'client'      => $client,
            'days'        => $days,
            'clients'     => $request->user()->isAdmin() ? Client::orderBy('name')->get() : collect(),
            'stats'       => $this->funnel($client->id, $since),
            'daily'       => $this->daily($client->id, $since),
            'byPlatform'  => $this->byPlatform($client->id, $since),
            'byDevice'    => $this->byDevice($client->id, $since),
            'dropoff'     => $this->dropoff($client->id, $since),
            'topCapsules' => $this->topCapsules($client->id, $since),
            'recentLeads' => Lead::where('client_id', $client->id)
                ->with('capsule')
                ->latest('submitted_at')
                ->limit(8)
                ->get(),
        ]);
    }

    /**
     * L'entonnoir : visites → formulaires commencés → demandes complétées.
     * Une seule requête agrégée plutôt qu'un COUNT par type.
     */
    private function funnel(int $clientId, $since): array
    {
        $counts = Event::where('client_id', $clientId)
            ->where('created_at', '>=', $since)
            ->selectRaw('type, COUNT(*) as n, COUNT(DISTINCT session_id) as sessions')
            ->groupBy('type')
            ->get()
            ->keyBy('type');

        $visits  = (int) ($counts['pageview']->sessions ?? 0);
        $starts  = (int) ($counts['form_start']->sessions ?? 0);
        $submits = (int) ($counts['submit']->sessions ?? 0);

        return [
            'visits'       => $visits,
            'starts'       => $starts,
            'submits'      => $submits,
            // Le taux qui compte pour le client : sur 100 personnes qui
            // cliquent depuis une capsule, combien laissent leurs coordonnées.
            'conversion'   => $visits > 0 ? round($submits / $visits * 100, 1) : 0.0,
            // Parmi ceux qui ont commencé à remplir, combien vont au bout.
            'completion'   => $starts > 0 ? round($submits / $starts * 100, 1) : 0.0,
        ];
    }

    /** Courbe jour par jour : visites et demandes. */
    private function daily(int $clientId, $since): array
    {
        $rows = Event::where('client_id', $clientId)
            ->where('created_at', '>=', $since)
            ->whereIn('type', ['pageview', 'submit'])
            ->selectRaw('DATE(created_at) as jour, type, COUNT(DISTINCT session_id) as n')
            ->groupBy('jour', 'type')
            ->orderBy('jour')
            ->get();

        $series = [];

        foreach ($rows as $r) {
            $series[$r->jour][$r->type] = (int) $r->n;
        }

        // On remplit les jours creux : sans ça, le graphique relie deux points
        // distants et laisse croire à une activité qui n'a pas eu lieu.
        $out    = [];
        $cursor = $since->copy();

        while ($cursor <= now()) {
            $key = $cursor->format('Y-m-d');
            $out[] = [
                'date'    => $key,
                'visits'  => $series[$key]['pageview'] ?? 0,
                'submits' => $series[$key]['submit'] ?? 0,
            ];
            $cursor->addDay();
        }

        return $out;
    }

    /** D'où viennent les visites : TikTok, Facebook, Instagram, YouTube. */
    private function byPlatform(int $clientId, $since): array
    {
        return Event::where('client_id', $clientId)
            ->where('created_at', '>=', $since)
            ->whereIn('type', ['pageview', 'submit'])
            ->selectRaw("COALESCE(platform, 'direct') as platform, type, COUNT(DISTINCT session_id) as n")
            ->groupBy('platform', 'type')
            ->get()
            ->groupBy('platform')
            ->map(fn ($rows) => [
                'visits'  => (int) ($rows->firstWhere('type', 'pageview')->n ?? 0),
                'submits' => (int) ($rows->firstWhere('type', 'submit')->n ?? 0),
            ])
            ->sortByDesc('visits')
            ->toArray();
    }

    private function byDevice(int $clientId, $since): array
    {
        return Event::where('client_id', $clientId)
            ->where('created_at', '>=', $since)
            ->where('type', 'pageview')
            ->selectRaw("COALESCE(device, 'inconnu') as device, COUNT(DISTINCT session_id) as n")
            ->groupBy('device')
            ->orderByDesc('n')
            ->pluck('n', 'device')
            ->toArray();
    }

    /**
     * Où les visiteurs abandonnent le formulaire.
     * C'est ce qui dit quelle étape décourage et mérite d'être allégée.
     */
    private function dropoff(int $clientId, $since): array
    {
        return Event::where('client_id', $clientId)
            ->where('created_at', '>=', $since)
            ->where('type', 'abandon')
            ->whereNotNull('step')
            ->selectRaw('step, COUNT(DISTINCT session_id) as n')
            ->groupBy('step')
            ->orderBy('step')
            ->pluck('n', 'step')
            ->toArray();
    }

    /**
     * Le classement des capsules : laquelle rapporte réellement des demandes.
     * Trié par nombre de demandes, pas par visites — une capsule très vue qui
     * ne convertit pas vaut moins qu'une capsule modeste qui convertit.
     */
    private function topCapsules(int $clientId, $since): array
    {
        return DB::table('events')
            ->join('capsules', 'capsules.id', '=', 'events.capsule_id')
            ->where('events.client_id', $clientId)
            ->where('events.created_at', '>=', $since)
            ->whereIn('events.type', ['pageview', 'submit'])
            ->selectRaw("
                capsules.id,
                capsules.number,
                capsules.title,
                COUNT(DISTINCT CASE WHEN events.type = 'pageview' THEN events.session_id END) as visits,
                COUNT(DISTINCT CASE WHEN events.type = 'submit'   THEN events.session_id END) as submits
            ")
            ->groupBy('capsules.id', 'capsules.number', 'capsules.title')
            ->orderByDesc('submits')
            ->orderByDesc('visits')
            ->limit(10)
            ->get()
            ->map(fn ($r) => [
                'id'         => $r->id,
                'number'     => $r->number,
                'title'      => $r->title,
                'visits'     => (int) $r->visits,
                'submits'    => (int) $r->submits,
                'conversion' => $r->visits > 0 ? round($r->submits / $r->visits * 100, 1) : 0.0,
            ])
            ->toArray();
    }

    /** Vue d'ensemble : une ligne par client. */
    private function portfolio($since): array
    {
        $stats = DB::table('events')
            ->where('created_at', '>=', $since)
            ->selectRaw("
                client_id,
                COUNT(DISTINCT CASE WHEN type = 'pageview' THEN session_id END) as visits,
                COUNT(DISTINCT CASE WHEN type = 'submit'   THEN session_id END) as submits
            ")
            ->groupBy('client_id')
            ->get()
            ->keyBy('client_id');

        return Client::orderBy('name')->get()->map(function ($c) use ($stats) {
            $visits  = (int) ($stats[$c->id]->visits ?? 0);
            $submits = (int) ($stats[$c->id]->submits ?? 0);

            return [
                'client'     => $c,
                'visits'     => $visits,
                'submits'    => $submits,
                'conversion' => $visits > 0 ? round($submits / $visits * 100, 1) : 0.0,
            ];
        })->toArray();
    }

    private function portfolioTotals($since): array
    {
        $visits = Event::where('created_at', '>=', $since)
            ->where('type', 'pageview')
            ->distinct('session_id')
            ->count('session_id');

        $submits = Lead::where('created_at', '>=', $since)->count();

        return [
            'clients'    => Client::where('active', true)->count(),
            'visits'     => $visits,
            'submits'    => $submits,
            'conversion' => $visits > 0 ? round($submits / $visits * 100, 1) : 0.0,
        ];
    }
}
