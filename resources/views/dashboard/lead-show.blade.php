@extends('layouts.app')
@section('titre', $lead->name)

@section('contenu')
<div class="tete">
    <div>
        <p class="sous"><a href="{{ route('leads.index') }}">← Demandes</a></p>
        <h1>{{ $lead->name }}</h1>
        <p class="sous">
            Reçue le {{ optional($lead->submitted_at)->translatedFormat('j F Y à H:i') }}
            — {{ $lead->client->name }}
        </p>
    </div>
    <div class="outils">
        @if($lead->phone)
            <a class="bouton bouton-accent" href="tel:{{ preg_replace('/[^0-9+]/', '', $lead->phone) }}">
                Appeler {{ $lead->phone }}
            </a>
        @endif
        <a class="bouton-fin" href="mailto:{{ $lead->email }}">Écrire</a>
    </div>
</div>

<div class="grille grille-2">
    <div class="panneau">
        <h2>Le projet</h2>
        <div class="tableau-cadre">
            <table>
                <tbody>
                <tr>
                    <th style="width:170px">Développement</th>
                    <td>
                        @if($lead->project_label)
                            <strong style="color:var(--accent)">{{ $lead->project_label }}</strong>
                        @else — @endif
                    </td>
                </tr>
                <tr><th>Type d'habitation</th><td>{{ $lead->home_type_label ?: '—' }}</td></tr>
                <tr><th>Chambres</th><td>{{ $lead->bedrooms_label ?: '—' }}</td></tr>
                <tr><th>Budget</th><td>{{ $lead->budget_label ?: '—' }}</td></tr>
                <tr>
                    <th>Financement</th>
                    <td>
                        @if($lead->financing === 'oui')
                            <span class="pastille pastille-gagne">{{ $lead->financing_label }}</span>
                        @elseif($lead->financing)
                            <span class="pastille">{{ $lead->financing_label }}</span>
                        @else — @endif
                    </td>
                </tr>
                <tr><th>Échéancier</th><td>{{ $lead->timeline_label ?: '—' }}</td></tr>
                <tr>
                    <th style="vertical-align:top">Message</th>
                    <td style="white-space:pre-wrap">{{ $lead->message ?: '—' }}</td>
                </tr>
                </tbody>
            </table>
        </div>

        <h2 style="border-top:1px solid var(--trait)">Coordonnées</h2>
        <div class="tableau-cadre">
            <table>
                <tbody>
                <tr><th style="width:170px">Téléphone</th><td>{{ $lead->phone ?: '—' }}</td></tr>
                <tr><th>Courriel</th><td><a href="mailto:{{ $lead->email }}">{{ $lead->email }}</a></td></tr>
                <tr><th>Langue</th><td>{{ $lead->locale === 'en' ? 'Anglais' : 'Français' }}</td></tr>
                </tbody>
            </table>
        </div>
    </div>

    <div>
        <div class="panneau" style="margin-bottom:16px">
            <h2>Suivi</h2>
            <div class="panneau-corps">
                <form method="post" action="{{ route('leads.update', $lead) }}">
                    @csrf
                    @method('patch')

                    <div class="champ">
                        <label for="status">Statut</label>
                        <select name="status" id="status">
                            @foreach($statuts as $s)
                                <option value="{{ $s }}" @selected($lead->status === $s)>{{ ucfirst($s) }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div class="champ">
                        <label for="notes">Notes internes</label>
                        <textarea name="notes" id="notes"
                                  placeholder="Suite de la conversation, rappel à faire…">{{ old('notes', $lead->notes) }}</textarea>
                    </div>

                    <button type="submit" class="bouton">Enregistrer</button>
                </form>
            </div>
        </div>

        <div class="panneau">
            <h2>Provenance</h2>
            <div class="tableau-cadre">
                <table>
                    <tbody>
                    <tr>
                        <th style="width:110px">Capsule</th>
                        <td>
                            @if($lead->capsule)
                                <a href="{{ route('capsules.show', $lead->capsule) }}">
                                    <strong>#{{ $lead->capsule->number }}</strong> {{ $lead->capsule->title }}
                                </a>
                            @else — @endif
                        </td>
                    </tr>
                    <tr>
                        <th>Réseau</th>
                        <td>
                            @if($lead->platform)
                                <span class="reseau"><i class="point point-{{ $lead->platform }}"></i>{{ ucfirst($lead->platform) }}</span>
                            @else — @endif
                        </td>
                    </tr>
                    <tr><th>Appareil</th><td>{{ $lead->device ? ucfirst($lead->device) : '—' }}</td></tr>
                    <tr><th>Campagne</th><td>{{ $lead->utm_campaign ?: '—' }}</td></tr>
                    <tr><th>Source</th><td>{{ $lead->utm_source ?: '—' }}</td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
@endsection
