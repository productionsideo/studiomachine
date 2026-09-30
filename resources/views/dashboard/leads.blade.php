@extends('layouts.app')
@section('titre', 'Demandes')

@section('contenu')
<div class="tete">
    <div>
        <h1>Demandes</h1>
        <p class="sous">{{ $leads->total() }} demande(s){{ $client ? ' — '.$client->name : '' }}</p>
    </div>
    <div class="outils">
        <a class="bouton-fin" href="{{ route('leads.export', request()->query()) }}">Exporter en CSV</a>
    </div>
</div>

<div class="panneau" style="margin-bottom:16px">
    <div class="panneau-corps">
        <form method="get" style="display:flex;gap:10px;flex-wrap:wrap;align-items:end">
            @if($clients->isNotEmpty())
                <div style="min-width:170px">
                    <label>Client</label>
                    <select name="client">
                        <option value="">Tous</option>
                        @foreach($clients as $c)
                            <option value="{{ $c->slug }}" @selected(request('client') === $c->slug)>{{ $c->name }}</option>
                        @endforeach
                    </select>
                </div>
            @endif

            <div style="min-width:150px">
                <label>Statut</label>
                <select name="statut">
                    <option value="">Tous</option>
                    @foreach($statuts as $s)
                        <option value="{{ $s }}" @selected(request('statut') === $s)>{{ ucfirst($s) }}</option>
                    @endforeach
                </select>
            </div>

            <div style="min-width:200px">
                <label>Développement</label>
                <select name="projet">
                    <option value="">Tous</option>
                    @foreach($projets as $cle => $libelle)
                        <option value="{{ $cle }}" @selected(request('projet') === $cle)>{{ $libelle }}</option>
                    @endforeach
                </select>
            </div>

            <div style="min-width:100px">
                <label>Capsule n°</label>
                <input type="text" name="capsule" value="{{ request('capsule') }}" placeholder="ex. 12">
            </div>

            <div style="flex:1;min-width:180px">
                <label>Recherche</label>
                <input type="search" name="q" value="{{ request('q') }}" placeholder="Nom, courriel, téléphone">
            </div>

            <button type="submit" class="bouton">Filtrer</button>
            @if(request()->hasAny(['statut','capsule','q','client','projet']))
                <a class="bouton-fin" href="{{ route('leads.index') }}">Réinitialiser</a>
            @endif
        </form>
    </div>
</div>

<div class="panneau">
    <div class="tableau-cadre">
        <table>
            <thead>
            <tr>
                <th>Reçue</th>
                @if($clients->isNotEmpty())<th>Client</th>@endif
                <th>Nom</th><th>Coordonnées</th>
                <th>Développement</th><th>Budget</th><th>Financement</th><th>Échéancier</th>
                <th>Provenance</th><th>Statut</th>
            </tr>
            </thead>
            <tbody>
            @forelse($leads as $l)
                <tr onclick="location='{{ route('leads.show', $l) }}'" style="cursor:pointer">
                    <td style="white-space:nowrap;color:var(--encre-3)">
                        {{ optional($l->submitted_at)->translatedFormat('j M Y') }}<br>
                        <span style="font-size:12px">{{ optional($l->submitted_at)->format('H:i') }}</span>
                    </td>
                    @if($clients->isNotEmpty())
                        <td>{{ $l->client->name ?? '—' }}</td>
                    @endif
                    <td><strong>{{ $l->name }}</strong></td>
                    <td style="font-size:13px">
                        {{ $l->phone ?: '—' }}<br>
                        <span style="color:var(--encre-3)">{{ $l->email }}</span>
                    </td>
                    <td style="font-size:13px">{{ $l->project_label ?: '—' }}</td>
                    <td>{{ $l->budget_label ?: '—' }}</td>
                    <td>
                        @if($l->financing === 'oui')
                            <span class="pastille pastille-gagne">Pré-approuvé</span>
                        @else
                            <span style="font-size:13px">{{ $l->financing_label ?: '—' }}</span>
                        @endif
                    </td>
                    <td style="font-size:13px">{{ $l->timeline_label ?: '—' }}</td>
                    <td style="font-size:13px">
                        @if($l->capsule)
                            <strong>#{{ $l->capsule->number }}</strong>
                        @endif
                        @if($l->platform)
                            <span class="reseau"><i class="point point-{{ $l->platform }}"></i>{{ ucfirst($l->platform) }}</span>
                        @endif
                        @if(! $l->capsule && ! $l->platform) — @endif
                    </td>
                    <td><span class="pastille pastille-{{ $l->status }}">{{ $l->status }}</span></td>
                </tr>
            @empty
                <tr>
                    <td colspan="10" class="vide">
                        Aucune demande ne correspond.
                    </td>
                </tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>

{{ $leads->links() }}
@endsection
