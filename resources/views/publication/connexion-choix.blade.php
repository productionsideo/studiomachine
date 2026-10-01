@extends('layouts.app')
@section('titre', 'Choisir le compte')

@section('contenu')
<div class="tete">
    <div>
        <h1>Quel compte brancher ?</h1>
        <p class="sous">{{ $client->name }} — ce compte donne accès à plusieurs Pages ou chaînes. Choisissez celle du client.</p>
    </div>
</div>

<div class="panneau">
    <div class="panneau-corps">
        <form method="post" action="{{ route('connexion.choisir') }}">
            @csrf
            <input type="hidden" name="jeton" value="{{ $jeton }}">
            <div class="coches">
                @foreach($groupes as $groupe => $libelle)
                    <label><input type="radio" name="groupe" value="{{ $groupe }}" required @checked($loop->first)> {{ $libelle }}</label>
                @endforeach
            </div>
            <button type="submit" class="bouton bouton-accent">Brancher ce compte</button>
            <a href="{{ route('integrations.index', ['client' => $client->slug]) }}" class="bouton-fin" style="margin-left:8px">Annuler</a>
        </form>
    </div>
</div>
@endsection
