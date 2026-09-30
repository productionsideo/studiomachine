@extends('layouts.app')
@section('titre', $client->exists ? $client->name : 'Nouveau client')

@section('contenu')
<div class="tete">
    <div>
        <p class="sous"><a href="{{ route('clients.index') }}">← Clients</a></p>
        <h1>{{ $client->exists ? $client->name : 'Nouveau client' }}</h1>
    </div>
</div>

@if(session('nouvelle_cle'))
    <div class="avis avis-garde">
        <strong>Voici la clé API de ce client. Elle ne sera plus jamais affichée.</strong>
        Copiez-la maintenant dans la configuration de son site.
        <div class="cle">{{ session('nouvelle_cle') }}</div>
    </div>
@endif

<div class="grille grille-2">
    <div class="panneau">
        <h2>Informations</h2>
        <div class="panneau-corps">
            <form method="post"
                  action="{{ $client->exists ? route('clients.update', $client) : route('clients.store') }}">
                @csrf
                @if($client->exists) @method('patch') @endif

                <div class="champ">
                    <label for="name">Nom de l'entreprise</label>
                    <input type="text" id="name" name="name"
                           value="{{ old('name', $client->name) }}" required>
                    @error('name') <div class="erreur">{{ $message }}</div> @enderror
                </div>

                <div class="champ">
                    <label for="slug">Identifiant court</label>
                    <input type="text" id="slug" name="slug"
                           value="{{ old('slug', $client->slug) }}" required
                           placeholder="construction-crd" pattern="[a-z0-9\-]+">
                    <div class="sous" style="font-size:12px;color:var(--encre-3);margin-top:4px">
                        Minuscules, chiffres et tirets. Sert dans les adresses du dashboard.
                    </div>
                    @error('slug') <div class="erreur">{{ $message }}</div> @enderror
                </div>

                <div class="champ">
                    <label for="website">Site web</label>
                    <input type="url" id="website" name="website"
                           value="{{ old('website', $client->website) }}"
                           placeholder="https://maison-neuve.constructioncrd.com">
                    @error('website') <div class="erreur">{{ $message }}</div> @enderror
                </div>

                <div class="champ">
                    <label for="accent_color">Couleur d'accent</label>
                    <input type="text" id="accent_color" name="accent_color"
                           value="{{ old('accent_color', $client->accent_color ?: '#9A0F20') }}"
                           pattern="#[0-9A-Fa-f]{6}">
                </div>

                @if($client->exists)
                    <div class="champ">
                        <label style="font-weight:400;display:flex;align-items:center;gap:8px;cursor:pointer">
                            <input type="checkbox" name="active" value="1"
                                   @checked($client->active) style="width:auto">
                            Client actif
                        </label>
                        <div style="font-size:12px;color:var(--encre-3);margin-top:4px">
                            Désactiver coupe l'ingestion : le site du client cesse d'être accepté par l'API.
                        </div>
                    </div>
                @endif

                <button type="submit" class="bouton bouton-accent">
                    {{ $client->exists ? 'Enregistrer' : 'Créer le client' }}
                </button>
            </form>
        </div>
    </div>

    @if($client->exists)
        <div>
            <div class="panneau" style="margin-bottom:16px">
                <h2>Clé API</h2>
                <div class="panneau-corps">
                    <p style="font-size:13px;color:var(--encre-2);margin-bottom:12px">
                        Le site du client s'authentifie avec cette clé pour envoyer ses visites
                        et ses demandes. Elle est stockée hachée : on ne peut pas la retrouver,
                        seulement en émettre une nouvelle.
                    </p>
                    <p style="font-size:13px;margin-bottom:12px">
                        Clé actuelle : <code>{{ $client->api_key_prefix }}…</code>
                    </p>
                    <form method="post" action="{{ route('clients.key', $client) }}"
                          onsubmit="return confirm('L\'ancienne clé cessera immédiatement de fonctionner. Le site du client n\'enverra plus rien tant que sa configuration ne sera pas mise à jour. Continuer ?')">
                        @csrf
                        <button type="submit" class="bouton-fin">Émettre une nouvelle clé</button>
                    </form>
                </div>
            </div>

            <div class="panneau">
                <h2>Accès au dashboard</h2>
                <div class="tableau-cadre">
                    <table>
                        <tbody>
                        @forelse($client->users as $u)
                            <tr>
                                <td>
                                    <strong>{{ $u->name }}</strong><br>
                                    <span style="color:var(--encre-3);font-size:12px">{{ $u->email }}</span>
                                </td>
                                <td class="num">
                                    <span class="pastille {{ $u->active ? 'pastille-gagne' : 'pastille-perdu' }}">
                                        {{ $u->active ? 'actif' : 'inactif' }}
                                    </span>
                                </td>
                            </tr>
                        @empty
                            <tr><td class="vide">Aucun accès client ouvert.</td></tr>
                        @endforelse
                        </tbody>
                    </table>
                </div>
                <div class="panneau-corps" style="border-top:1px solid var(--trait)">
                    <form method="post" action="{{ route('clients.adduser', $client) }}">
                        @csrf
                        <div class="champ">
                            <label for="u_name">Nom du contact</label>
                            <input type="text" id="u_name" name="name" required>
                        </div>
                        <div class="champ">
                            <label for="u_email">Courriel</label>
                            <input type="email" id="u_email" name="email" required>
                            @error('email') <div class="erreur">{{ $message }}</div> @enderror
                        </div>
                        <div class="champ">
                            <label for="u_password">Mot de passe provisoire</label>
                            <input type="text" id="u_password" name="password" required minlength="12"
                                   value="{{ \Illuminate\Support\Str::random(16) }}">
                            @error('password') <div class="erreur">{{ $message }}</div> @enderror
                        </div>
                        <button type="submit" class="bouton">Ouvrir l'accès</button>
                    </form>
                </div>
            </div>
        </div>
    @endif
</div>
@endsection
