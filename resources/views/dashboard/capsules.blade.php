@extends('layouts.app')
@section('titre', 'Capsules')

@section('contenu')
<div class="tete">
    <div>
        <h1>Capsules</h1>
        <p class="sous">{{ $client->name }} — {{ $days }} derniers jours, classées par demandes générées.</p>
    </div>
    <div class="outils">
        @foreach([7 => '7 j', 30 => '30 j', 90 => '90 j', 365 => '1 an'] as $d => $label)
            <a href="{{ route('capsules.index', ['client' => $client->slug, 'jours' => $d]) }}"
               @class(['bouton-fin', 'on' => $days === $d])>{{ $label }}</a>
        @endforeach
    </div>
</div>

@php
    $aucuneVue = collect($capsules)->sum('views') === 0;
@endphp

@if($aucuneVue && count($capsules) > 0)
    <div class="avis avis-info">
        La colonne <strong>Vues</strong> reste vide tant que les comptes TikTok, Facebook, Instagram
        et YouTube ne sont pas connectés. Les colonnes Visites, Demandes et Conversion, elles,
        sont mesurées directement sur la page et sont déjà fiables.
    </div>
@endif

<div class="panneau">
    <h2>
        Classement
        <span class="aide">une capsule très vue qui ne convertit pas vaut moins qu'une capsule modeste qui convertit</span>
    </h2>
    <div class="tableau-cadre">
        <table>
            <thead>
            <tr>
                <th>Capsule</th>
                <th class="num">Vues</th>
                <th class="num">Visites</th>
                <th class="num">Clic (%)</th>
                <th class="num">Demandes</th>
                <th class="num">Conversion</th>
                <th style="width:130px">Part des demandes</th>
            </tr>
            </thead>
            <tbody>
            @php $maxEnvois = max(1, collect($capsules)->max('submits')); @endphp

            @forelse($capsules as $c)
                <tr onclick="location='{{ route('capsules.show', [$c['capsule'], 'jours' => $days]) }}'" style="cursor:pointer">
                    <td>
                        <strong>#{{ $c['capsule']->number }}</strong>
                        <span style="color:var(--encre-3)">{{ $c['capsule']->title }}</span>
                    </td>
                    <td class="num">{{ $c['views'] ? number_format($c['views'], 0, ',', ' ') : '—' }}</td>
                    <td class="num">{{ number_format($c['visits'], 0, ',', ' ') }}</td>
                    <td class="num">{{ $c['ctr'] !== null ? number_format($c['ctr'], 2, ',', ' ').' %' : '—' }}</td>
                    <td class="num"><strong>{{ $c['submits'] }}</strong></td>
                    <td class="num">{{ number_format($c['conversion'], 1, ',', ' ') }}&nbsp;%</td>
                    <td>
                        <div class="jauge">
                            <div class="jauge-rail">
                                <div class="jauge-part" style="width:{{ $c['submits'] / $maxEnvois * 100 }}%"></div>
                            </div>
                        </div>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="7" class="vide">
                        Aucune capsule enregistrée.<br>
                        <span style="font-size:13px">
                            Chaque capsule apparaît automatiquement dès le premier clic sur son lien.
                        </span>
                    </td>
                </tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection
