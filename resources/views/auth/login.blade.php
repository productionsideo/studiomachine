@extends('layouts.app')
@section('titre', 'Connexion')

@section('contenu')
<div class="portail">
    <div class="portail-boite">
        <div class="portail-marque">
            <span class="rail-logo">SM</span>
            <strong>Studio Machine</strong>
        </div>
        <h1>Gestion</h1>
        <p class="sous">Statistiques et demandes de vos campagnes.</p>

        <form method="post" action="{{ route('login') }}">
            @csrf

            <div class="champ">
                <label for="email">Courriel</label>
                <input type="email" id="email" name="email" value="{{ old('email') }}"
                       required autofocus autocomplete="username">
                @error('email') <div class="erreur">{{ $message }}</div> @enderror
            </div>

            <div class="champ">
                <label for="password">Mot de passe</label>
                <input type="password" id="password" name="password"
                       required autocomplete="current-password">
            </div>

            <div class="champ">
                <label style="font-weight:400;display:flex;align-items:center;gap:8px;cursor:pointer">
                    <input type="checkbox" name="remember" value="1" style="width:auto">
                    Rester connecté
                </label>
            </div>

            <button type="submit" class="bouton bouton-accent">Se connecter</button>
        </form>
    </div>
</div>
@endsection
