<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ResolvesClient;
use App\Models\Campaign;
use App\Models\Client;
use App\Models\Event;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Les campagnes : des publications regroupées, et ce qu'elles ont rapporté.
 */
class CampaignController extends Controller
{
    use ResolvesClient;

    public function index(Request $request)
    {
        $client = $this->resolveClient($request);

        $campagnes = Campaign::query()
            ->when($client, fn ($q) => $q->where('client_id', $client->id))
            ->with('client')
            ->withCount([
                'posts',
                'posts as publiees_count'    => fn ($q) => $q->where('status', 'publiee'),
                'posts as programmees_count' => fn ($q) => $q->where('status', 'programmee'),
            ])
            ->orderBy('archived')
            ->orderByDesc('starts_on')
            ->orderByDesc('id')
            ->get();

        // Les demandes rapportées, par campagne : une seule requête groupée.
        $carte    = Campaign::parCode($campagnes);
        $demandes = \App\Models\Lead::query()
            ->whereIn('utm_campaign', array_unique(array_map(fn ($k) => explode('|', $k, 2)[1], array_keys($carte))))
            ->when($client, fn ($q) => $q->where('client_id', $client->id))
            ->selectRaw('client_id, utm_campaign, count(*) as n')
            ->groupBy('client_id', 'utm_campaign')
            ->get()
            ->groupBy(fn ($r) => $carte["{$r->client_id}|{$r->utm_campaign}"] ?? 0)
            ->map(fn ($g) => (int) $g->sum('n'));

        return view('publication.campagnes', [
            'client'    => $client,
            'campagnes' => $campagnes,
            'demandes'  => $demandes,
        ]);
    }

    public function show(Request $request, Campaign $campaign)
    {
        $this->authorizeClient($request, $campaign->client_id);

        $posts = $campaign->posts()->with('targets', 'media')->orderByRaw('scheduled_at is null')->orderBy('scheduled_at')->get();

        $visites = Event::where('client_id', $campaign->client_id)
            ->whereIn('utm_campaign', $campaign->codes())
            ->where('type', 'pageview')
            ->distinct('session_id')
            ->count('session_id');

        $demandes = $campaign->leads()->count();

        $parReseau = $campaign->leads()
            ->selectRaw("coalesce(utm_source, 'inconnu') as source, count(*) as n")
            ->groupBy('source')
            ->pluck('n', 'source');

        // Google Analytics 4, si le client est branché : la même campagne vue
        // par GA4 (achats, clics vers Amazon), par source / support.
        $ga4 = null;
        if ($campaign->client->integrations()->where('platform', 'ga4')->whereNotNull('last_synced_at')->exists()) {
            $trafic = \Illuminate\Support\Facades\DB::table('ga4_trafic')
                ->where('client_id', $campaign->client_id)->whereIn('campagne', $campaign->codes());
            $clics = \Illuminate\Support\Facades\DB::table('ga4_evenements')
                ->where('client_id', $campaign->client_id)->whereIn('campagne', $campaign->codes())->where('evenement', 'clic_amazon');

            $ga4 = [
                'totaux'    => (clone $trafic)->selectRaw('sum(sessions) s, sum(sessions_engagees) e, sum(achats) a, sum(revenus) r')->first(),
                'clics'     => (int) (clone $clics)->sum('nombre'),
                'parSource' => (clone $trafic)->selectRaw('source, support, sum(sessions) s, sum(achats) a')->groupBy('source', 'support')->orderByDesc('s')->get(),
                'clicsParSource' => (clone $clics)->selectRaw('source, sum(nombre) n')->groupBy('source')->pluck('n', 'source'),
            ];
        }

        return view('publication.campagne-show', compact('campaign', 'posts', 'visites', 'demandes', 'parReseau', 'ga4'));
    }

    public function create(Request $request)
    {
        $client = $this->resolveClient($request);

        if (! $client) {
            return view('publication.choisir-client', [
                'clients' => Client::where('active', true)->orderBy('name')->get(),
                'suite'   => 'campaigns.create',
            ]);
        }

        $campaign = new Campaign(['client_id' => $client->id, 'color' => $client->accent_color ?: '#9A0F20']);

        return view('publication.campagne-form', [
            'campaign'  => $campaign,
            'client'    => $client,
            'detectes'  => $this->codesDetectes($campaign),
        ]);
    }

    public function store(Request $request)
    {
        $client = Client::findOrFail($request->input('client_id'));
        $this->authorizeClient($request, $client->id);

        $campaign = new Campaign(['client_id' => $client->id]);
        $this->enregistrer($request, $campaign);

        return redirect()->route('campaigns.show', $campaign)->with('ok', 'Campagne créée.');
    }

    public function edit(Request $request, Campaign $campaign)
    {
        $this->authorizeClient($request, $campaign->client_id);

        return view('publication.campagne-form', [
            'campaign' => $campaign,
            'client'   => $campaign->client,
            'detectes' => $this->codesDetectes($campaign),
        ]);
    }

    public function update(Request $request, Campaign $campaign)
    {
        $this->authorizeClient($request, $campaign->client_id);
        $this->enregistrer($request, $campaign);

        return redirect()->route('campaigns.show', $campaign)->with('ok', 'Campagne enregistrée.');
    }

    /**
     * Supprime la campagne. Ses publications perdent leur campagne
     * (nullOnDelete) ; sur demande, celles qui ne sont pas parties sont
     * supprimées aussi. Une publication en ligne ou en cours d'envoi ne l'est
     * jamais : effacer la ligne ne l'effacerait pas du réseau.
     */
    public function destroy(Request $request, Campaign $campaign)
    {
        $this->authorizeClient($request, $campaign->client_id);

        $request->validate(['publications' => ['nullable', Rule::in(['garder', 'supprimer'])]]);

        $supprimees = 0;

        DB::transaction(function () use ($request, $campaign, &$supprimees) {
            if ($request->input('publications') === 'supprimer') {
                $campaign->posts()
                    ->whereDoesntHave('targets', fn ($q) => $q->whereIn('status', ['publiee', 'en_cours']))
                    ->get()
                    ->each(function ($post) use (&$supprimees) {
                        $post->delete();
                        $supprimees++;
                    });
            }

            $campaign->delete();
        });

        return redirect()->route('campaigns.index', ['client' => $campaign->client->slug])
            ->with('ok', "Campagne « {$campaign->name} » supprimée"
                . ($supprimees ? " et {$supprimees} " . ($supprimees > 1 ? 'publications non parties' : 'publication non partie') : '') . '.');
    }

    private function enregistrer(Request $request, Campaign $campaign): void
    {
        $d = $request->validate([
            'name'         => ['required', 'string', 'max:255'],
            'objective'    => ['nullable', 'string', 'max:2000'],
            'color'        => ['required', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'utm_campaign' => [
                'nullable', 'string', 'max:100', 'regex:/^[a-z0-9_-]+$/',
                Rule::unique('campaigns')->where('client_id', $campaign->client_id)->ignore($campaign->id),
            ],
            'starts_on'    => ['nullable', 'date'],
            'ends_on'      => ['nullable', 'date', 'after_or_equal:starts_on'],
            'archived'     => ['nullable', 'boolean'],
            'codes_suivi'           => ['nullable', 'array'],
            'codes_suivi.*'         => ['string', 'max:100'],
            'codes_supplementaires' => ['nullable', 'string', 'max:2000'],
        ], [
            'utm_campaign.regex'  => 'Lettres minuscules, chiffres, tirets et soulignés seulement (il voyage dans les liens).',
            'utm_campaign.unique' => 'Ce code est déjà pris par une autre campagne de ce client.',
        ]);

        // Le code de suivi ne change pas une fois des liens publiés avec lui :
        // les demandes déjà reçues ne seraient plus rattachées à la campagne.
        if ($campaign->exists && $campaign->posts()->where('status', '!=', 'brouillon')->exists()) {
            unset($d['utm_campaign']);
        } elseif (empty($d['utm_campaign'])) {
            $d['utm_campaign'] = $this->codeLibre($campaign, Str::slug($d['name'], '_') ?: 'campagne');
        }

        $d['archived'] = $request->boolean('archived');

        // Les codes des publicités rattachées : cochés dans la liste, ou collés à la main.
        $codes = collect($d['codes_suivi'] ?? [])
            ->merge(preg_split('/[\s,;]+/', (string) ($d['codes_supplementaires'] ?? ''), -1, PREG_SPLIT_NO_EMPTY))
            ->map(fn ($c) => mb_substr(trim($c), 0, 100))
            ->filter()->unique()->reject(fn ($c) => $c === ($d['utm_campaign'] ?? $campaign->utm_campaign))
            ->values();

        // Un code n'appartient qu'à une campagne : sinon une visite compterait deux fois.
        $pris = Campaign::proprietaires($campaign->client_id, $campaign->id);
        if ($doublons = $codes->filter(fn ($c) => isset($pris[$c]))->values()->all()) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'codes_suivi' => 'Déjà rattaché à une autre campagne : ' . implode(', ', array_map(fn ($c) => "{$c} ({$pris[$c]->name})", $doublons)) . '.',
            ]);
        }

        unset($d['codes_supplementaires']);
        $d['codes_suivi'] = $codes->all() ?: null;

        $campaign->fill($d)->save();
    }

    /**
     * Les codes de suivi vus sur le site du client (visites et demandes), du
     * plus fréquent au plus rare, avec la campagne qui les a déjà, s'il y en a
     * une. Ce sont surtout les identifiants des campagnes publicitaires
     * (Meta, Metricool) que les annonces mettent dans le lien.
     */
    private function codesDetectes(Campaign $campaign): \Illuminate\Support\Collection
    {
        $visites = DB::table('events')->where('client_id', $campaign->client_id)->where('type', 'pageview')->whereNotNull('utm_campaign')
            ->selectRaw('utm_campaign code, count(distinct session_id) visites, min(created_at) debut, max(created_at) fin, group_concat(distinct utm_source) sources')
            ->groupBy('utm_campaign')->get()->keyBy('code');
        $demandes = DB::table('leads')->where('client_id', $campaign->client_id)->whereNotNull('utm_campaign')
            ->selectRaw('utm_campaign code, count(*) n')->groupBy('utm_campaign')->pluck('n', 'code');

        $proprietaires = Campaign::proprietaires($campaign->client_id, $campaign->id);

        // Les codes déjà rattachés restent visibles (et décochables) même s'ils n'ont encore rien amené.
        return $visites->keys()->merge($demandes->keys())->merge($campaign->codes_suivi ?? [])->unique()
            ->reject(fn ($code) => $code === $campaign->utm_campaign)
            ->map(fn ($code) => [
                'code'     => $code,
                'visites'  => (int) ($visites[$code]->visites ?? 0),
                'demandes' => (int) ($demandes[$code] ?? 0),
                'sources'  => $visites[$code]->sources ?? null,
                'debut'    => isset($visites[$code]) ? \Illuminate\Support\Carbon::parse($visites[$code]->debut) : null,
                'fin'      => isset($visites[$code]) ? \Illuminate\Support\Carbon::parse($visites[$code]->fin) : null,
                'ailleurs' => ($proprietaires[(string) $code] ?? null)?->name,
            ])
            ->sortByDesc('visites')->values()->take(40);
    }

    /** Le code tiré du nom, suffixé s'il est déjà pris chez ce client. */
    private function codeLibre(Campaign $campaign, string $base): string
    {
        $base = mb_substr($base, 0, 90);
        $code = $base;

        for ($i = 2; Campaign::where('client_id', $campaign->client_id)
            ->where('utm_campaign', $code)
            ->where('id', '!=', $campaign->id ?? 0)
            ->exists(); $i++) {
            $code = "{$base}_{$i}";
        }

        return $code;
    }
}
