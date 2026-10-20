@extends('layouts.app')
@section('titre', 'Connexion')

@section('contenu')
<div class="portail">
    <div class="portail-boite">
        <div class="portail-marque">
            <img class="logo-sm" src="{{ $asset('assets/logo-sm.svg') }}" alt="Studio Machine">
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

        {{-- Liens exigés par les règles des services d'API YouTube. --}}
        <p style="font-size:12px;color:var(--encre-3);margin-top:20px;text-align:center">
            <a href="https://studiomachine.ca/fr/confidentialite/">Politique de confidentialité</a> ·
            <a href="https://studiomachine.ca/fr/conditions/">Conditions d’utilisation</a><br>
            Studio Machine utilise les services d’API YouTube
            (<a href="https://www.youtube.com/t/terms">conditions de YouTube</a>,
            <a href="https://policies.google.com/privacy">règles de confidentialité de Google</a>).
        </p>
    </div>
</div>
@endsection
