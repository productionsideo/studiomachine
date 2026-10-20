<?php

namespace App\Services;

use App\Models\Campaign;
use App\Models\Client;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Les chiffres de la vue d'ensemble (accueil de l'équipe) et de la page
 * Clients : chaque campagne depuis son début, chaque client sur une période.
 *
 * D'où viennent les chiffres d'une campagne
 * -----------------------------------------
 *   - publications : ses publications dans /gestion, par état ;
 *   - vues, interactions : stats_publications reliées à ses cibles — donc
 *     seulement ce qui a été publié PAR /gestion (une campagne publiée
 *     ailleurs, ex. Metricool, n'en a pas) ;
 *   - visites, demandes : par son code de suivi (utm_campaign), quel que soit
 *     l'outil qui a publié.
 * Une requête groupée par source, jamais une par campagne.
 */
class VueEnsemble
{
    /** @return Collection<int, array> une entrée par campagne, la plus récente d'abord */
    public function campagnes(): Collection
    {
        $campagnes = Campaign::with('client')
            ->where('archived', false)
            ->whereHas('client', fn ($q) => $q->where('active', true))
            ->orderByRaw('starts_on is null')
            ->orderByDesc('starts_on')
            ->orderByDesc('id')
            ->get();

        if ($campagnes->isEmpty()) {
            return collect();
        }

        $ids = $campagnes->pluck('id');

        $etats = DB::table('posts')->whereIn('campaign_id', $ids)
            ->selectRaw('campaign_id, status, count(*) n')->groupBy('campaign_id', 'status')->get()
            ->groupBy('campaign_id');

        $reseaux = DB::table('post_targets')->join('posts', 'posts.id', '=', 'post_targets.post_id')
            ->whereIn('posts.campaign_id', $ids)->where('post_targets.status', 'publiee')
            ->selectRaw('posts.campaign_id, post_targets.platform, count(*) n')
            ->groupBy('posts.campaign_id', 'post_targets.platform')->get()
            ->groupBy('campaign_id');

        $stats = DB::table('stats_publications as s')
            ->join('post_targets as t', 't.id', '=', 's.post_target_id')
            ->join('posts as p', 'p.id', '=', 't.post_id')
            ->whereIn('p.campaign_id', $ids)
            ->selectRaw('p.campaign_id, sum(s.vues) vues, sum(s.interactions) interactions')
            ->groupBy('p.campaign_id')->get()->keyBy('campaign_id');

        // Les vues par jour de publication, pour la mini-courbe.
        $parJour = DB::table('stats_publications as s')
            ->join('post_targets as t', 't.id', '=', 's.post_target_id')
            ->join('posts as p', 'p.id', '=', 't.post_id')
            ->whereIn('p.campaign_id', $ids)
            ->get(['p.campaign_id', 's.publie_le', 's.vues'])
            ->groupBy('campaign_id');

        // Chaque code (le sien et ceux de ses publicités) ramène à sa campagne.
        $carte    = Campaign::parCode($campagnes);
        $codes    = array_unique(array_map(fn ($k) => explode('|', $k, 2)[1], array_keys($carte)));
        $ventiler = fn ($lignes) => $lignes->groupBy(fn ($r) => $carte["{$r->client_id}|{$r->utm_campaign}"] ?? 0)->map(fn ($g) => (int) $g->sum('n'));

        $visites = $ventiler(DB::table('events')->where('type', 'pageview')->whereIn('utm_campaign', $codes)
            ->selectRaw('client_id, utm_campaign, count(distinct session_id) n')->groupBy('client_id', 'utm_campaign')->get());
        $demandes = $ventiler(DB::table('leads')->whereIn('utm_campaign', $codes)
            ->selectRaw('client_id, utm_campaign, count(*) n')->groupBy('client_id', 'utm_campaign')->get());

        $fuseau = config('publication.fuseau');
        $auj    = now($fuseau)->startOfDay();

        return $campagnes->map(function (Campaign $c) use ($etats, $reseaux, $stats, $parJour, $visites, $demandes, $fuseau, $auj) {
            $e      = ($etats[$c->id] ?? collect())->pluck('n', 'status');
            $vis    = $visites[$c->id] ?? 0;
            $dem    = $demandes[$c->id] ?? 0;
            $s      = $stats[$c->id] ?? null;

            $etat = match (true) {
                $c->starts_on && $c->starts_on->gt($auj) => 'a_venir',
                $c->ends_on && $c->ends_on->lt($auj)     => 'terminee',
                default                                  => 'en_cours',
            };

            return [
                'campagne'     => $c,
                'etat'         => $etat,
                'publiees'     => (int) (($e['publiee'] ?? 0) + ($e['partielle'] ?? 0)),
                'programmees'  => (int) (($e['programmee'] ?? 0) + ($e['en_cours'] ?? 0)),
                'brouillons'   => (int) ($e['brouillon'] ?? 0),
                'reseaux'      => ($reseaux[$c->id] ?? collect())->pluck('n', 'platform')->all(),
                'vues'         => $s?->vues !== null ? (int) $s->vues : null,
                'interactions' => $s?->interactions !== null ? (int) $s->interactions : null,
                'visites'      => $vis,
                'demandes'     => $dem,
                'conversion'   => $vis > 0 ? round($dem / $vis * 100, 1) : null,
                'courbe'       => $this->courbe($c, $parJour[$c->id] ?? collect(), $fuseau, $auj),
            ];
        })
            // En cours d'abord, puis à venir, puis terminées ; la plus récente en tête dans chaque groupe.
            ->sortBy(fn ($k) => ['en_cours' => 0, 'a_venir' => 1, 'terminee' => 2][$k['etat']], SORT_NUMERIC)
            ->values();
    }

    /**
     * Les clients actifs, avec leurs chiffres sur la période et leurs comptes
     * connectés : de quoi entrer dans un client en un clic.
     */
    public function clients(Carbon $depuis): Collection
    {
        $events = DB::table('events')->where('created_at', '>=', $depuis)
            ->selectRaw("client_id, COUNT(DISTINCT CASE WHEN type = 'pageview' THEN session_id END) as visites")
            ->groupBy('client_id')->get()->keyBy('client_id');

        // Les demandes viennent de la table, comme l'onglet Demandes : les ventes
        // (BJ21) y arrivent sans événement « submit » du script de suivi.
        $demandes = DB::table('leads')->where('submitted_at', '>=', $depuis)
            ->selectRaw('client_id, count(*) n')->groupBy('client_id')->pluck('n', 'client_id');

        $publiees = DB::table('posts')->where('status', 'publiee')->where('scheduled_at', '>=', $depuis)
            ->selectRaw('client_id, count(*) n')->groupBy('client_id')->pluck('n', 'client_id');

        $aVenir = DB::table('posts')->where('status', 'programmee')->where('scheduled_at', '>=', now())
            ->selectRaw('client_id, count(*) n')->groupBy('client_id')->pluck('n', 'client_id');

        return Client::where('active', true)->with(['integrations' => fn ($q) => $q->where('active', true)])
            ->withCount(['campaigns' => fn ($q) => $q->where('archived', false)])
            ->orderBy('name')->get()
            ->map(fn (Client $c) => [
                'client'    => $c,
                'visites'   => (int) ($events[$c->id]->visites ?? 0),
                'demandes'  => (int) ($demandes[$c->id] ?? 0),
                'publiees'  => (int) ($publiees[$c->id] ?? 0),
                'a_venir'   => (int) ($aVenir[$c->id] ?? 0),
                'reseaux'   => $c->integrations->pluck('platform')->reject(fn ($p) => $p === 'ga4')->values()->all(),
                'erreurs'   => $c->integrations->filter(fn ($i) => $i->last_error)->count(),
            ]);
    }

    /** Vues par jour de publication, du début de la campagne à aujourd'hui (60 jours au plus). */
    private function courbe(Campaign $c, Collection $lignes, string $fuseau, Carbon $auj): array
    {
        if ($lignes->isEmpty()) {
            return [];
        }

        $debut = ($c->starts_on ? Carbon::parse($c->starts_on->toDateString(), $fuseau) : Carbon::parse($lignes->min('publie_le'), 'UTC')->setTimezone($fuseau))->startOfDay();
        $fin   = $c->ends_on && Carbon::parse($c->ends_on->toDateString(), $fuseau)->lt($auj) ? Carbon::parse($c->ends_on->toDateString(), $fuseau) : $auj;
        $debut = $debut->max($fin->copy()->subDays(59));

        $vues = $lignes->groupBy(fn ($l) => Carbon::parse($l->publie_le, 'UTC')->setTimezone($fuseau)->toDateString())
            ->map(fn ($g) => (int) $g->sum('vues'));

        $serie = [];
        for ($j = $debut->copy(); $j->lte($fin); $j->addDay()) {
            $serie[$j->toDateString()] = $vues[$j->toDateString()] ?? 0;
        }

        return $serie;
    }
}
