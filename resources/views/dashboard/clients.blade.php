@extends('layouts.app')
@section('titre', 'Clients')

@section('contenu')
<div class="tete">
    <div>
        <h1>Clients</h1>
        <p class="sous">Un clic pour entrer dans chaque client — chiffres des 30 derniers jours.</p>
    </div>
    <div class="outils">
        <a class="bouton bouton-accent" href="{{ route('clients.create') }}">Ajouter un client</a>
    </div>
</div>

@if($actifs->isEmpty())
    <div class="panneau"><p class="vide" style="padding:18px">Aucun client actif. Commencez par en ajouter un.</p></div>
@else
    <div class="cartes-clients">
        @foreach($actifs as $k)
            @include('dashboard._carte-client', ['k' => $k, 'days' => 30])
        @endforeach
    </div>
@endif

@if($inactifs->isNotEmpty())
    <div class="panneau" style="margin-top:18px">
        <h2>Clients inactifs</h2>
        <div class="tableau-cadre">
            <table>
                <tbody>
                @foreach($inactifs as $c)
                    <tr>
                        <td><strong>{{ $c->name }}</strong> <span style="color:var(--encre-3);font-size:12px">{{ $c->slug }}</span></td>
                        <td class="num"><a class="bouton-fin" href="{{ route('clients.edit', $c) }}">Paramètres</a></td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    </div>
@endif
@endsection
