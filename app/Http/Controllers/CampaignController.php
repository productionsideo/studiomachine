<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ResolvesClient;
use App\Models\Campaign;
use App\Models\Client;
use App\Models\Event;
use Illuminate\Http\Request;
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
        $demandes = \App\Models\Lead::query()
            ->whereIn('utm_campaign', $campagnes->pluck('utm_campaign')->filter())
            ->when($client, fn ($q) => $q->where('client_id', $client->id))
            ->selectRaw('client_id, utm_campaign, count(*) as n')
            ->groupBy('client_id', 'utm_campaign')
            ->get()
            ->mapWithKeys(fn ($r) => ["{$r->client_id}|{$r->utm_campaign}" => $r->n]);

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
            ->where('utm_campaign', $campaign->utm_campaign)
            ->where('type', 'pageview')
            ->distinct('session_id')
            ->count('session_id');

        $demandes = $campaign->leads()->count();

        $parReseau = $campaign->leads()
            ->selectRaw("coalesce(utm_source, 'inconnu') as source, count(*) as n")
            ->groupBy('source')
            ->pluck('n', 'source');

        return view('publication.campagne-show', compact('campaign', 'posts', 'visites', 'demandes', 'parReseau'));
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

        return view('publication.campagne-form', [
            'campaign' => new Campaign(['client_id' => $client->id, 'color' => $client->accent_color ?: '#9A0F20']),
            'client'   => $client,
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

        return view('publication.campagne-form', ['campaign' => $campaign, 'client' => $campaign->client]);
    }

    public function update(Request $request, Campaign $campaign)
    {
        $this->authorizeClient($request, $campaign->client_id);
        $this->enregistrer($request, $campaign);

        return redirect()->route('campaigns.show', $campaign)->with('ok', 'Campagne enregistrée.');
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

        $campaign->fill($d)->save();
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
