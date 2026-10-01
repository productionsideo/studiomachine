@extends('layouts.app')
@section('titre', $campaign->name)

@php use App\Services\Publication\Reseaux; $admin = auth()->user()->isAdmin(); @endphp

@section('contenu')
<div class="tete">
    <div>
        <h1><span class="pastille-couleur" style="background:{{ $campaign->color }};width:14px;height:14px"></span>{{ $campaign->name }}</h1>
        <p class="sous">
            {{ $campaign->client->name }}
            · {{ $campaign->starts_on?->translatedFormat('j F Y') ?? '…' }} → {{ $campaign->ends_on?->translatedFormat('j F Y') ?? '…' }}
            · code de suivi <code>{{ $campaign->utm_campaign }}</code>
        </p>
    </div>
    @if($admin)
        <div class="outils">
            <a class="bouton-fin" href="{{ route('campaigns.edit', $campaign) }}">Modifier</a>
            <a class="bouton bouton-accent" href="{{ route('posts.create', ['client' => $campaign->client->slug, 'campagne' => $campaign->id]) }}">Ajouter une publication</a>
        </div>
    @endif
</div>

@if($campaign->objective)
    <div class="avis avis-info">{{ $campaign->objective }}</div>
@endif

<div class="chiffres">
    <div class="chiffre"><div class="etiquette">Publications</div><div class="valeur">{{ $posts->count() }}</div>
        <div class="note">{{ $posts->where('status', 'publiee')->count() }} publiées · {{ $posts->where('status', 'programmee')->count() }} à venir</div></div>
    <div class="chiffre"><div class="etiquette">Visites amenées</div><div class="valeur">{{ number_format($visites, 0, ',', ' ') }}</div>
        <div class="note">sessions arrivées par un lien de la campagne</div></div>
    <div class="chiffre fort"><div class="etiquette">Demandes</div><div class="valeur">{{ $demandes }}</div>
        <div class="note">
            @forelse($parReseau as $source => $n) {{ $source }} {{ $n }}@if(! $loop->last) · @endif @empty aucune pour l’instant @endforelse
        </div></div>
    <div class="chiffre"><div class="etiquette">Conversion</div>
        <div class="valeur">{{ $visites ? number_format($demandes / $visites * 100, 1, ',', ' ') . ' %' : '—' }}</div>
        <div class="note">demandes ÷ visites</div></div>
</div>

@if($ga4)
    <div class="panneau" style="margin-bottom:16px">
        <h2>Google Analytics 4
            <span class="aide">
                {{ number_format((float) $ga4['totaux']->s, 0, ',', ' ') }} sessions ·
                {{ $ga4['clics'] }} clics vers Amazon ·
                {{ (int) $ga4['totaux']->a }} achats{{ $ga4['totaux']->r > 0 ? ' · ' . number_format($ga4['totaux']->r, 2, ',', ' ') . ' $' : '' }}
            </span>
        </h2>
        <div class="tableau-cadre">
            <table>
                <thead><tr><th>Source / support</th><th class="num">Sessions</th><th class="num">Clics Amazon</th><th class="num">Achats</th></tr></thead>
                <tbody>
                @forelse($ga4['parSource'] as $l)
                    <tr>
                        <td>{{ $l->source }} <span style="color:var(--encre-3)">/ {{ $l->support }}</span></td>
                        <td class="num">{{ number_format($l->s, 0, ',', ' ') }}</td>
                        <td class="num">{{ $ga4['clicsParSource'][$l->source] ?? 0 }}</td>
                        <td class="num">{{ $l->a }}</td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="vide">GA4 n’a encore vu aucune visite portant le code <code>{{ $campaign->utm_campaign }}</code>.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>
@endif

<div class="panneau">
    <h2>Publications</h2>
    <div class="tableau-cadre">
        <table>
            <thead><tr><th>Quand</th><th>Publication</th><th>Réseaux</th><th>État</th></tr></thead>
            <tbody>
            @forelse($posts as $p)
                <tr onclick="location='{{ route('posts.show', $p) }}'" style="cursor:pointer">
                    <td style="white-space:nowrap">{{ $p->heureLocale()?->format('Y-m-d H:i') ?? '—' }}</td>
                    <td>{{ $p->title ?: \Illuminate\Support\Str::limit($p->caption, 70) ?: 'Sans texte' }}</td>
                    <td>@foreach($p->targets as $t)<i class="point point-{{ $t->platform }}" title="{{ Reseaux::nom($t->platform) }}" style="margin-right:3px"></i>@endforeach</td>
                    <td><span class="pastille pastille-{{ $p->status }}">{{ \App\Models\Post::STATUTS[$p->status] }}</span></td>
                </tr>
            @empty
                <tr><td colspan="4" class="vide">Aucune publication dans cette campagne.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection
