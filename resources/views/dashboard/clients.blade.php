@extends('layouts.app')
@section('titre', 'Clients')

@section('contenu')
<div class="tete">
    <div>
        <h1>Clients</h1>
        <p class="sous">Les entreprises branchées sur la plateforme.</p>
    </div>
    <div class="outils">
        <a class="bouton bouton-accent" href="{{ route('clients.create') }}">Ajouter un client</a>
    </div>
</div>

<div class="panneau">
    <div class="tableau-cadre">
        <table>
            <thead>
            <tr>
                <th>Client</th><th>Site</th>
                <th class="num">Demandes</th><th class="num">Accès</th>
                <th>État</th><th></th>
            </tr>
            </thead>
            <tbody>
            @forelse($clients as $c)
                <tr>
                    <td>
                        <strong>{{ $c->name }}</strong><br>
                        <span style="color:var(--encre-3);font-size:12px">{{ $c->slug }}</span>
                    </td>
                    <td style="font-size:13px">
                        @if($c->website)
                            <a href="{{ $c->website }}" target="_blank" rel="noopener">
                                {{ parse_url($c->website, PHP_URL_HOST) }} ↗
                            </a>
                        @else — @endif
                    </td>
                    <td class="num">{{ $c->leads_count }}</td>
                    <td class="num">{{ $c->users_count }}</td>
                    <td>
                        <span class="pastille {{ $c->active ? 'pastille-gagne' : 'pastille-perdu' }}">
                            {{ $c->active ? 'actif' : 'inactif' }}
                        </span>
                    </td>
                    <td class="num">
                        <a class="bouton-fin" href="{{ route('clients.edit', $c) }}">Gérer</a>
                    </td>
                </tr>
            @empty
                <tr><td colspan="6" class="vide">Aucun client. Commencez par en ajouter un.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection
