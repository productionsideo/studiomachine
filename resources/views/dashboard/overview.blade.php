@extends('layouts.app')
@section('titre', 'Tableau de bord')

@section('contenu')
<div class="tete">
    <div>
        <h1>Portefeuille</h1>
        <p class="sous">Tous les clients, sur les {{ $days }} derniers jours.</p>
    </div>
    <div class="outils">
        @foreach([7 => '7 j', 30 => '30 j', 90 => '90 j', 365 => '1 an'] as $d => $label)
            <a href="{{ route('dashboard', ['jours' => $d]) }}"
               @class(['bouton-fin', 'on' => $days === $d])>{{ $label }}</a>
        @endforeach
    </div>
</div>

<div class="chiffres">
    <div class="chiffre">
        <div class="etiquette">Clients actifs</div>
        <div class="valeur">{{ $totals['clients'] }}</div>
    </div>
    <div class="chiffre">
        <div class="etiquette">Visites</div>
        <div class="valeur">{{ number_format($totals['visits'], 0, ',', ' ') }}</div>
        <div class="note">Clics arrivés depuis les publications</div>
    </div>
    <div class="chiffre fort">
        <div class="etiquette">Demandes</div>
        <div class="valeur">{{ number_format($totals['submits'], 0, ',', ' ') }}</div>
        <div class="note">Formulaires complétés</div>
    </div>
    <div class="chiffre">
        <div class="etiquette">Conversion</div>
        <div class="valeur">{{ number_format($totals['conversion'], 1, ',', ' ') }}&nbsp;%</div>
        <div class="note">Visiteurs devenus demandes</div>
    </div>
</div>

<div class="panneau">
    <h2>Par client</h2>
    <div class="tableau-cadre">
        <table>
            <thead>
            <tr>
                <th>Client</th>
                <th class="num">Visites</th>
                <th class="num">Demandes</th>
                <th class="num">Conversion</th>
                <th></th>
            </tr>
            </thead>
            <tbody>
            @forelse($clients as $ligne)
                @php $c = $ligne['client']; @endphp
                <tr>
                    <td>
                        <strong>{{ $c->name }}</strong>
                        @unless($c->active)
                            <span class="pastille pastille-perdu">inactif</span>
                        @endunless
                    </td>
                    <td class="num">{{ number_format($ligne['visits'], 0, ',', ' ') }}</td>
                    <td class="num"><strong>{{ number_format($ligne['submits'], 0, ',', ' ') }}</strong></td>
                    <td class="num">{{ number_format($ligne['conversion'], 1, ',', ' ') }}&nbsp;%</td>
                    <td class="num">
                        <a class="bouton-fin" href="{{ route('dashboard', ['client' => $c->slug, 'jours' => $days]) }}">
                            Ouvrir
                        </a>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="5" class="vide">
                        Aucun client pour l'instant.
                        <a href="{{ route('clients.create') }}" style="color:var(--accent);font-weight:600">
                            Ajouter le premier
                        </a>
                    </td>
                </tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection
