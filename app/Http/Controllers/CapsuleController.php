<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ResolvesClient;
use App\Models\Capsule;
use App\Models\Client;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * La performance des capsules : celle qui répond à « laquelle de nos
 * 72 vidéos rapporte des clients ? ».
 */
class CapsuleController extends Controller
{
    use ResolvesClient;

    public function index(Request $request)
    {
        $client = $this->resolveClient($request);

        // Sans client choisi, la question n'a pas de sens : on renvoie l'admin
        // vers le choix d'un client.
        if (! $client) {
            return redirect()->route('dashboard');
        }

        $this->authorizeClient($request, $client->id);

        $days  = $this->resolveDays($request);
        $since = now()->subDays($days)->startOfDay();

        return view('dashboard.capsules', [
            'client'   => $client,
            'clients'  => $request->user()->isAdmin() ? Client::orderBy('name')->get() : collect(),
            'days'     => $days,
            'capsules' => $this->ranking($client->id, $since),
        ]);
    }

    public function show(Request $request, Capsule $capsule)
    {
        $this->authorizeClient($request, $capsule->client_id);

        $days  = $this->resolveDays($request);
        $since = now()->subDays($days)->startOfDay();

        return view('dashboard.capsule-show', [
            'capsule'    => $capsule->load('posts.latestMetric'),
            'client'     => $capsule->client,
            'days'       => $days,
            'byPlatform' => $this->capsuleByPlatform($capsule->id, $since),
            'leads'      => $capsule->leads()->latest('submitted_at')->limit(20)->get(),
        ]);
    }

    /**
     * Le tableau complet : visites, demandes, taux, et les vues sociales
     * quand les réseaux sont branchés.
     *
     * Les vues sociales viennent du dernier instantané connu de chaque
     * publication — une sous-requête plutôt qu'une jointure directe, sinon
     * on additionnerait tous les instantanés historiques d'une même vidéo.
     */
    private function ranking(int $clientId, $since): array
    {
        $trafic = DB::table('events')
            ->where('client_id', $clientId)
            ->where('created_at', '>=', $since)
            ->whereNotNull('capsule_id')
            ->selectRaw("
                capsule_id,
                COUNT(DISTINCT CASE WHEN type = 'pageview' THEN session_id END) as visits,
                COUNT(DISTINCT CASE WHEN type = 'submit'   THEN session_id END) as submits
            ")
            ->groupBy('capsule_id')
            ->get()
            ->keyBy('capsule_id');

        $social = DB::table('capsule_posts as cp')
            ->join('capsules as c', 'c.id', '=', 'cp.capsule_id')
            ->leftJoin('social_metrics as sm', function ($join) {
                $join->on('sm.capsule_post_id', '=', 'cp.id')
                     ->whereRaw('sm.collected_on = (
                         SELECT MAX(collected_on) FROM social_metrics
                         WHERE capsule_post_id = cp.id
                     )');
            })
            ->where('c.client_id', $clientId)
            ->selectRaw('cp.capsule_id, SUM(sm.views) as views, SUM(sm.comments_count) as comments')
            ->groupBy('cp.capsule_id')
            ->get()
            ->keyBy('capsule_id');

        return Capsule::where('client_id', $clientId)
            ->orderBy('number')
            ->get()
            ->map(function ($c) use ($trafic, $social) {
                $visits  = (int) ($trafic[$c->id]->visits ?? 0);
                $submits = (int) ($trafic[$c->id]->submits ?? 0);
                $views   = (int) ($social[$c->id]->views ?? 0);

                return [
                    'capsule'    => $c,
                    'views'      => $views,       // vues de la vidéo sur les réseaux
                    'visits'     => $visits,      // clics arrivés sur la page
                    'submits'    => $submits,     // demandes complétées
                    'comments'   => (int) ($social[$c->id]->comments ?? 0),
                    // Sur 100 personnes qui ont vu la vidéo, combien ont cliqué.
                    'ctr'        => $views > 0 ? round($visits / $views * 100, 2) : null,
                    // Sur 100 personnes arrivées sur la page, combien ont laissé leurs coordonnées.
                    'conversion' => $visits > 0 ? round($submits / $visits * 100, 1) : 0.0,
                ];
            })
            ->sortByDesc('submits')
            ->values()
            ->toArray();
    }

    private function capsuleByPlatform(int $capsuleId, $since): array
    {
        return DB::table('events')
            ->where('capsule_id', $capsuleId)
            ->where('created_at', '>=', $since)
            ->selectRaw("
                COALESCE(platform, 'direct') as platform,
                COUNT(DISTINCT CASE WHEN type = 'pageview' THEN session_id END) as visits,
                COUNT(DISTINCT CASE WHEN type = 'submit'   THEN session_id END) as submits
            ")
            ->groupBy('platform')
            ->orderByDesc('visits')
            ->get()
            ->toArray();
    }
}
