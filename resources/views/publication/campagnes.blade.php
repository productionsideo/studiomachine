@extends('layouts.app')
@section('titre', 'Campagnes')

@section('contenu')
<div class="tete">
    <div>
        <h1>Campagnes</h1>
        <p class="sous">{{ $client?->name ?? 'Tous les clients' }} — les publications regroupées, et les demandes qu’elles ont amenées.</p>
    </div>
    @if(auth()->user()->isAdmin())
        <div class="outils">
            <a class="bouton bouton-accent" href="{{ route('campaigns.create', $client ? ['client' => $client->slug] : []) }}">Nouvelle campagne</a>
        </div>
    @endif
</div>

<div class="panneau">
    <div class="tableau-cadre">
        <table>
            <thead>
            <tr>
                <th>Campagne</th>
                @if(! $client) <th>Client</th> @endif
                <th>Période</th>
                <th class="num">Publications</th>
                <th class="num">Publiées</th>
                <th class="num">Programmées</th>
                <th class="num">Demandes</th>
            </tr>
            </thead>
            <tbody>
            @forelse($campagnes as $c)
                <tr onclick="location='{{ route('campaigns.show', $c) }}'" style="cursor:pointer;{{ $c->archived ? 'opacity:.55' : '' }}">
                    <td><span class="pastille-couleur" style="background:{{ $c->color }}"></span><strong>{{ $c->name }}</strong>
                        @if($c->archived) <span class="pastille">archivée</span> @endif</td>
                    @if(! $client) <td>{{ $c->client->name }}</td> @endif
                    <td style="color:var(--encre-3);font-size:13px">
                        {{ $c->starts_on?->format('Y-m-d') ?? '…' }} → {{ $c->ends_on?->format('Y-m-d') ?? '…' }}
                    </td>
                    <td class="num">{{ $c->posts_count }}</td>
                    <td class="num">{{ $c->publiees_count }}</td>
                    <td class="num">{{ $c->programmees_count }}</td>
                    <td class="num"><strong>{{ $demandes["{$c->client_id}|{$c->utm_campaign}"] ?? 0 }}</strong></td>
                </tr>
            @empty
                <tr><td colspan="7" class="vide">Aucune campagne pour l’instant.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection
