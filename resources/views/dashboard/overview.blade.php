@extends('layouts.app')
@section('titre', 'Tableau de bord')

@php
    use App\Http\Controllers\ResumeController as R;
    use App\Services\Publication\Reseaux;

    $nb  = fn ($v) => $v === null ? '—' : ($v >= 10000 ? number_format($v / 1000, 1, ',', ' ') . ' k' : number_format($v, 0, ',', ' '));
    $etats = ['en_cours' => 'En cours', 'a_venir' => 'À venir', 'terminee' => 'Terminée'];
    $filtre = request('campagnes', 'toutes');
    $liste  = $filtre === 'toutes' ? $campagnes : $campagnes->where('etat', $filtre);
@endphp

@section('contenu')
<div class="tete">
    <div>
        <h1>Vue d’ensemble</h1>
        <p class="sous">Toutes les campagnes à ce jour, et l’activité des {{ $days }} derniers jours.</p>
    </div>
    <div class="outils">
        <span class="bascule">
            @foreach([7 => '7 j', 30 => '30 j', 90 => '90 j', 365 => '1 an'] as $d => $label)
                <a href="{{ route('dashboard', ['jours' => $d] + request()->only('campagnes')) }}" @class(['bouton-fin', 'on' => $days === $d])>{{ $label }}</a>
            @endforeach
        </span>
    </div>
</div>

{{-- Les chiffres de la période --}}
<div class="chiffres chiffres-7">
    <div class="chiffre"><div class="etiquette">Clients actifs</div><div class="valeur">{{ $totals['clients'] }}</div></div>
    <div class="chiffre"><div class="etiquette">Campagnes en cours</div><div class="valeur">{{ $totals['campagnes_en_cours'] }}</div></div>
    <div class="chiffre"><div class="etiquette">Publications</div><div class="valeur">{{ $nb($totals['publiees']) }}</div><div class="note">parties par /gestion</div></div>
    <div class="chiffre"><div class="etiquette">Vues</div><div class="valeur">{{ $nb($totals['vues']) }}</div><div class="note">des publications de la période</div></div>
    <div class="chiffre"><div class="etiquette">Interactions</div><div class="valeur">{{ $nb($totals['interactions']) }}</div></div>
    <div class="chiffre"><div class="etiquette">Visites</div><div class="valeur">{{ $nb($totals['visits']) }}</div><div class="note">sur les sites des clients</div></div>
    <div class="chiffre fort"><div class="etiquette">Demandes</div><div class="valeur">{{ $nb($totals['submits']) }}</div><div class="note">{{ number_format($totals['conversion'], 1, ',', ' ') }} % des visites</div></div>
</div>

{{-- Les campagnes, chacune à part --}}
<div class="section-tete">
    <h2 class="section-resume">Campagnes <span class="aide">depuis leur début</span></h2>
    <span class="bascule">
        @foreach(['toutes' => 'Toutes', 'en_cours' => 'En cours', 'a_venir' => 'À venir', 'terminee' => 'Terminées'] as $v => $l)
            <a href="{{ route('dashboard', ['jours' => $days, 'campagnes' => $v]) }}" @class(['bouton-fin', 'on' => $filtre === $v])>{{ $l }}</a>
        @endforeach
    </span>
</div>

@if($liste->isEmpty())
    <div class="panneau"><p class="vide" style="padding:18px">Aucune campagne {{ $filtre === 'toutes' ? '' : mb_strtolower($etats[$filtre] ?? '') }} pour l’instant.
        <a href="{{ route('campaigns.index') }}"><u>Voir les campagnes</u></a></p></div>
@else
    <div class="cartes-campagnes">
        @foreach($liste as $k)
            @php $c = $k['campagne']; @endphp
            <a class="carte-campagne" href="{{ route('campaigns.show', $c) }}" style="--couleur: {{ $c->color }}">
                <div class="carte-tete">
                    <span class="nom"><i class="pastille-couleur" style="background:{{ $c->color }}"></i>{{ $c->name }}</span>
                    <span class="pastille pastille-etat-{{ $k['etat'] }}">{{ $etats[$k['etat']] }}</span>
                </div>
                <div class="carte-sous">
                    <strong>{{ $c->client->name }}</strong>
                    · {{ $c->starts_on?->translatedFormat('j M') ?? '…' }} → {{ $c->ends_on?->translatedFormat('j M Y') ?? '…' }}
                </div>

                <div class="carte-chiffres">
                    <div><span class="v">{{ $k['publiees'] }}</span><span class="e">publiées</span></div>
                    <div><span class="v">{{ $k['programmees'] }}</span><span class="e">programmées</span></div>
                    <div><span class="v">{{ $nb($k['vues']) }}</span><span class="e">vues</span></div>
                    <div><span class="v">{{ $nb($k['interactions']) }}</span><span class="e">interactions</span></div>
                    <div><span class="v">{{ $nb($k['visites']) }}</span><span class="e">visites</span></div>
                    <div class="fort"><span class="v">{{ $k['demandes'] }}</span><span class="e">demandes{{ $k['conversion'] !== null ? ' · ' . number_format($k['conversion'], 1, ',', ' ') . ' %' : '' }}</span></div>
                </div>

                @if($k['courbe'])
                    @include('resume._courbe', ['serie' => $k['courbe'], 'couleur' => R::COULEUR_TOTAL, 'mini' => true, 'barres' => true, 'nom' => 'vues'])
                @endif

                <div class="carte-pied">
                    @forelse($k['reseaux'] as $reseau => $n)
                        <span class="badge-compte">@include('publication._reseau', ['reseau' => $reseau]) {{ $n }}</span>
                    @empty
                        <span class="muet">{{ $k['brouillons'] ? $k['brouillons'] . ' brouillon' . ($k['brouillons'] > 1 ? 's' : '') . ' · ' : '' }}rien de publié par /gestion</span>
                    @endforelse
                </div>
            </a>
        @endforeach
    </div>
@endif

{{-- Les clients : un clic pour entrer --}}
<h2 class="section-resume">Clients <span class="aide">{{ $days }} derniers jours</span></h2>
<div class="cartes-clients">
    @foreach($clients as $k)
        @include('dashboard._carte-client', ['k' => $k, 'days' => $days])
    @endforeach
</div>
@endsection
