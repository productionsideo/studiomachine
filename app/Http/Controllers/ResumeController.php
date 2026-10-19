<?php

namespace App\Http\Controllers;

use App\Console\Commands\CollecterStatistiques;
use App\Http\Controllers\Concerns\ResolvesClient;
use App\Models\Client;
use App\Services\Publication\Reseaux;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Le tableau de bord Résumé d'un client (façon Metricool) : ses comptes
 * (abonnés, vues), ses publications (interactions, nombre, liste) et ses
 * comptes publicitaires — tous réseaux confondus, puis réseau par réseau.
 *
 * Les chiffres viennent de statistiques:collecter. Le client consulte ; seule
 * l'équipe peut lancer une collecte à la main.
 */
class ResumeController extends Controller
{
    use ResolvesClient;

    /** Couleur de chaque réseau : fixe, quel que soit le nombre de comptes affichés (palette validée). */
    public const COULEURS = [
        'facebook'        => '#2a78d6',
        'youtube'         => '#e34948',
        'instagram'       => '#6250d6',
        'tiktok'          => '#1baf7a',
        'linkedin'        => '#eda100',
        'linkedin_profil' => '#eda100',
    ];

    /** Couleur du total, une seule série : la couleur d'encre du site. */
    public const COULEUR_TOTAL = '#9a0f20';

    public function index(Request $request)
    {
        $client = $this->resolveClient($request);

        if (! $client) {
            return view('resume.choisir', ['clients' => Client::where('active', true)->orderBy('name')->get()]);
        }

        $this->authorizeClient($request, $client->id);

        $fuseau = config('publication.fuseau');
        $jours  = in_array((int) $request->query('jours'), [7, 30, 90], true) ? (int) $request->query('jours') : 30;
        $fin    = now($fuseau)->startOfDay();
        $debut  = $fin->copy()->subDays($jours - 1);
        $dates  = collect(range(0, $jours - 1))->map(fn ($i) => $debut->copy()->addDays($i)->toDateString());

        $comptes = $client->integrations()
            ->where('active', true)
            ->whereIn('platform', array_keys(Reseaux::CATALOGUE))
            ->get()
            ->sortBy(fn ($i) => array_search($i->platform, array_keys(self::COULEURS)))
            ->values();

        $collectes = array_keys(CollecterStatistiques::COLLECTEURS);

        // --- Compte : abonnés et vues, jour par jour --------------------------

        $lignes = DB::table('stats_comptes')
            ->where('client_id', $client->id)
            ->whereBetween('jour', [$debut->copy()->subDays($jours)->toDateString(), $fin->toDateString()])
            ->orderBy('jour')
            ->get()
            ->groupBy('integration_id');

        $datesPrec = $dates->map(fn ($d) => Carbon::parse($d)->subDays($jours)->toDateString());

        $abonnes  = [];
        $vues     = [];
        $vuesPrec = [];
        foreach ($comptes as $c) {
            $ligne = $lignes->get($c->id, collect())->keyBy(fn ($l) => Carbon::parse($l->jour)->toDateString());
            $abonnes[$c->platform] = $this->reporter($dates, $ligne, 'abonnes');
            $parJour = fn ($liste) => $c->platform === 'youtube'
                ? $this->ecarts($liste, $ligne, 'vues_cumulees')
                : $liste->mapWithKeys(fn ($d) => [$d => $ligne[$d]->vues ?? null])->all();
            $vues[$c->platform]     = $parJour($dates);
            // Rien de connu sur la période précédente = pas de tendance (et non « +100 % »).
            $connusPrec = array_filter($parJour($datesPrec), fn ($v) => $v !== null);
            $vuesPrec[$c->platform] = $connusPrec ? array_sum($connusPrec) : null;
        }

        // --- Publications : sur la période et la précédente (pour la tendance) --

        $pubs = DB::table('stats_publications')
            ->where('client_id', $client->id)
            ->where('publie_le', '>=', $debut->copy()->subDays($jours)->utc())
            ->get()
            ->map(function ($p) use ($fuseau) {
                $p->jour = Carbon::parse($p->publie_le, 'UTC')->setTimezone($fuseau)->toDateString();
                return $p;
            });

        $periode   = $pubs->filter(fn ($p) => $p->jour >= $dates->first());
        $precedent = $pubs->filter(fn ($p) => $p->jour < $dates->first());

        $interactions = [];
        $nombre       = [];
        foreach ($comptes->whereIn('platform', $collectes) as $c) {
            // Un réseau pas encore collecté n'a pas « zéro » publication : il n'a pas de chiffres.
            $parJour = $periode->where('platform', $c->platform)->groupBy('jour');
            $interactions[$c->platform] = $dates->mapWithKeys(fn ($d) => [$d => $parJour->has($d) ? (int) $parJour[$d]->sum('interactions') : 0])->all();
            $nombre[$c->platform]       = $dates->mapWithKeys(fn ($d) => [$d => $parJour->has($d) ? $parJour[$d]->count() : 0])->all();
        }

        // La première collecte remonte 90 jours : avant, rien n'a été lu, et une
        // période précédente non couverte ne donne pas de tendance.
        $premiere = DB::table('stats_publications')->where('client_id', $client->id)->min('created_at');
        $couverte = $premiere && Carbon::parse($premiere)->subDays(90)->lte($debut->copy()->subDays($jours));

        $tendances = $couverte ? [
            'interactions' => $this->tendanceSomme($periode, $precedent, 'interactions'),
            'nombre'       => $this->tendanceNombre($periode, $precedent),
        ] : ['interactions' => [], 'nombre' => []];

        return view('resume.index', [
            'client'       => $client,
            'clients'      => $request->user()->isAdmin() ? Client::where('active', true)->orderBy('name')->get() : collect(),
            'jours'        => $jours,
            'dates'        => $dates,
            'comptes'      => $comptes,
            'collectes'    => $collectes,
            'abonnes'      => $abonnes,
            'vues'         => $vues,
            'vuesPrec'     => $vuesPrec,
            'interactions' => $interactions,
            'nombre'       => $nombre,
            'tendances'    => $tendances,
            'liste'        => $this->liste($request, $client, $debut),
            'derniere'     => DB::table('stats_comptes')->where('client_id', $client->id)->max('updated_at'),
        ]);
    }

    /** Liste des publications en CSV (mêmes filtres que la page). */
    public function exporter(Request $request)
    {
        $client = $this->resolveClient($request) ?? abort(404);
        $this->authorizeClient($request, $client->id);

        $jours = in_array((int) $request->query('jours'), [7, 30, 90], true) ? (int) $request->query('jours') : 30;
        $debut = now(config('publication.fuseau'))->startOfDay()->subDays($jours - 1);

        $flux = fopen('php://temp', 'r+');
        fwrite($flux, "\u{FEFF}");
        fputcsv($flux, ['date', 'reseau', 'type', 'texte', 'vues', 'portee', 'interactions', 'j_aime', 'commentaires', 'partages', 'enregistrements', 'lien'], ',', '"', '');
        foreach ($this->requeteListe($request, $client, $debut)->get() as $p) {
            fputcsv($flux, [
                Carbon::parse($p->publie_le, 'UTC')->setTimezone(config('publication.fuseau'))->format('Y-m-d H:i'),
                Reseaux::nom($p->platform), $p->type, $p->texte,
                $p->vues, $p->portee, $p->interactions, $p->j_aime, $p->commentaires, $p->partages, $p->enregistrements, $p->lien,
            ], ',', '"', '');
        }
        rewind($flux);

        return response(stream_get_contents($flux), 200, [
            'Content-Type'        => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="publications-' . $client->slug . '-' . $jours . 'j.csv"',
        ]);
    }

    /** L'équipe lance une collecte sans attendre la prochaine passe (6 h). */
    public function collecter(Request $request, Client $client)
    {
        $this->authorizeClient($request, $client->id);

        \Illuminate\Support\Facades\Artisan::call('statistiques:collecter', ['--client' => $client->slug]);

        return back()->with('ok', 'Statistiques mises à jour.');
    }

    // =====================================================================

    /** Le dernier total connu, reporté sur les jours sans collecte (un total ne retombe pas à zéro). */
    private function reporter(Collection $dates, Collection $ligne, string $champ): array
    {
        $avant  = $ligne->filter(fn ($l, $d) => $d < $dates->first() && $l->{$champ} !== null)->last();
        $connu  = $avant?->{$champ};
        $serie  = [];

        foreach ($dates as $d) {
            if (isset($ligne[$d]) && $ligne[$d]->{$champ} !== null) {
                $connu = (int) $ligne[$d]->{$champ};
            }
            $serie[$d] = $connu;
        }

        return $serie;
    }

    /** Vues du jour = total du jour − total de la veille (YouTube ne donne que des totaux). */
    private function ecarts(Collection $dates, Collection $ligne, string $champ): array
    {
        $serie = [];
        foreach ($dates as $d) {
            $hier = Carbon::parse($d)->subDay()->toDateString();
            $serie[$d] = isset($ligne[$d], $ligne[$hier]) && $ligne[$d]->{$champ} !== null && $ligne[$hier]->{$champ} !== null
                ? max(0, (int) $ligne[$d]->{$champ} - (int) $ligne[$hier]->{$champ})
                : null;
        }

        return $serie;
    }

    private function tendanceSomme(Collection $periode, Collection $precedent, string $champ): array
    {
        $parReseau = [];
        foreach ($periode->pluck('platform')->merge($precedent->pluck('platform'))->unique() as $r) {
            $parReseau[$r] = [(int) $periode->where('platform', $r)->sum($champ), (int) $precedent->where('platform', $r)->sum($champ)];
        }

        return $parReseau;
    }

    private function tendanceNombre(Collection $periode, Collection $precedent): array
    {
        $parReseau = [];
        foreach ($periode->pluck('platform')->merge($precedent->pluck('platform'))->unique() as $r) {
            $parReseau[$r] = [$periode->where('platform', $r)->count(), $precedent->where('platform', $r)->count()];
        }

        return $parReseau;
    }

    private function requeteListe(Request $request, Client $client, Carbon $debut)
    {
        $tri = in_array($request->query('tri'), ['vues', 'interactions', 'j_aime', 'commentaires', 'publie_le'], true) ? $request->query('tri') : 'publie_le';

        return DB::table('stats_publications')
            ->where('client_id', $client->id)
            ->where('publie_le', '>=', $debut->copy()->utc())
            ->when($request->query('reseau'), fn ($q, $r) => $q->where('platform', $r))
            ->when(trim((string) $request->query('q')) !== '', fn ($q) => $q->where('texte', 'like', '%' . trim((string) $request->query('q')) . '%'))
            ->orderByRaw($tri === 'publie_le' ? 'publie_le desc' : "coalesce({$tri}, -1) desc");
    }

    private function liste(Request $request, Client $client, Carbon $debut)
    {
        return $this->requeteListe($request, $client, $debut)->paginate(10)->withQueryString();
    }
}
