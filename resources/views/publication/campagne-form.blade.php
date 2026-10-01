@extends('layouts.app')
@section('titre', $campaign->exists ? 'Modifier la campagne' : 'Nouvelle campagne')

@php $verrouille = $campaign->exists && $campaign->posts()->where('status', '!=', 'brouillon')->exists(); @endphp

@section('contenu')
<div class="tete">
    <div>
        <h1>{{ $campaign->exists ? 'Modifier la campagne' : 'Nouvelle campagne' }}</h1>
        <p class="sous">{{ $client->name }}</p>
    </div>
</div>

<div class="panneau" style="max-width:760px">
    <form method="post" action="{{ $campaign->exists ? route('campaigns.update', $campaign) : route('campaigns.store') }}">
        @csrf
        @if($campaign->exists) @method('patch') @endif
        <input type="hidden" name="client_id" value="{{ $client->id }}">

        <div class="panneau-corps">
            <div class="champ">
                <label for="name">Nom</label>
                <input type="text" id="name" name="name" required maxlength="255" value="{{ old('name', $campaign->name) }}">
                @error('name') <div class="erreur">{{ $message }}</div> @enderror
            </div>

            <div class="champ">
                <label for="objective">Objectif</label>
                <textarea id="objective" name="objective" rows="3" placeholder="Ex. : 40 demandes de soumission pour le projet Les Jardins d’ici la fin novembre.">{{ old('objective', $campaign->objective) }}</textarea>
            </div>

            <div class="rangee">
                <div class="champ">
                    <label for="starts_on">Début</label>
                    <input type="date" id="starts_on" name="starts_on" value="{{ old('starts_on', $campaign->starts_on?->toDateString()) }}">
                </div>
                <div class="champ">
                    <label for="ends_on">Fin</label>
                    <input type="date" id="ends_on" name="ends_on" value="{{ old('ends_on', $campaign->ends_on?->toDateString()) }}">
                    @error('ends_on') <div class="erreur">{{ $message }}</div> @enderror
                </div>
            </div>

            <div class="rangee">
                <div class="champ">
                    <label for="utm_campaign">Code de suivi (utm_campaign)</label>
                    <input type="text" id="utm_campaign" name="utm_campaign" maxlength="100" pattern="[a-z0-9_\-]+"
                           value="{{ old('utm_campaign', $campaign->utm_campaign) }}" placeholder="Tiré du nom si vide" @disabled($verrouille)>
                    @error('utm_campaign') <div class="erreur">{{ $message }}</div> @enderror
                    <div class="aide-champ">
                        @if($verrouille)
                            Figé : des liens ont déjà été publiés avec ce code.
                        @else
                            Ajouté à chaque lien publié ; c’est lui qui rattache les demandes reçues à la campagne.
                        @endif
                    </div>
                </div>
                <div class="champ">
                    <label for="color">Couleur dans le calendrier</label>
                    <input type="color" id="color" name="color" value="{{ old('color', $campaign->color) }}">
                </div>
            </div>

            @if($campaign->exists)
                <div class="coches">
                    <label><input type="checkbox" name="archived" value="1" @checked(old('archived', $campaign->archived))> Archivée (n’apparaît plus dans l’éditeur)</label>
                </div>
            @endif
        </div>

        <div class="barre-actions">
            <span class="espace"></span>
            <a class="bouton-fin" href="{{ $campaign->exists ? route('campaigns.show', $campaign) : route('campaigns.index', ['client' => $client->slug]) }}">Annuler</a>
            <button type="submit" class="bouton bouton-accent">Enregistrer</button>
        </div>
    </form>
</div>
@endsection
