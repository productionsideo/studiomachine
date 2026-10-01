@extends('layouts.app')
@section('titre', 'Intégrations')

@section('contenu')
<div class="tete">
    <div>
        <h1>Intégrations</h1>
        <p class="sous">{{ $client->name }} — comptes Analytics et réseaux sociaux.</p>
    </div>
</div>

<div class="avis avis-info">
    <strong>Ces connexions ne dépendent pas seulement de nous.</strong>
    Google Analytics se branche immédiatement. YouTube, Facebook, Instagram et TikTok exigent
    d'abord une application développeur au nom de Studio Machine, et une approbation de la
    plateforme. Tant que ces démarches ne sont pas faites, les boutons ci-dessous restent inactifs —
    c'est volontaire : un bouton qui ne mène nulle part vaut moins qu'une explication.
</div>

@foreach($plateformes as $cle => $p)
    @php
        $branchee    = $branchees[$cle] ?? null;
        $configuree  = in_array($cle, $configurees, true);
        $fournisseur = match ($cle) { 'facebook', 'instagram' => 'meta', default => $cle };
    @endphp

    <div class="panneau" style="margin-bottom:16px">
        <h2>
            <span class="reseau">
                <i class="point point-{{ $cle === 'ga4' ? 'direct' : $cle }}"></i>
                {{ $p['nom'] }}
            </span>

            @if($branchee)
                <span class="pastille pastille-gagne">Connecté</span>
            @elseif(! $configuree)
                <span class="pastille pastille-qualifie">Application à créer</span>
            @else
                <span class="pastille">Prêt à connecter</span>
            @endif
        </h2>

        <div class="panneau-corps">
            <div class="tableau-cadre">
                <table>
                    <tbody>
                    <tr>
                        <th style="width:150px">Ce qu'on en tire</th>
                        <td>{{ $p['donne'] }}</td>
                    </tr>
                    <tr>
                        <th>Ce qu'il faut</th>
                        <td>{{ $p['exige'] }}</td>
                    </tr>
                    <tr>
                        <th>Délai</th>
                        <td>
                            @if($p['approbation'])
                                <span style="color:var(--alerte);font-weight:600">{{ $p['delai'] }}</span>
                            @else
                                <span style="color:var(--ok);font-weight:600">{{ $p['delai'] }}</span>
                            @endif
                        </td>
                    </tr>
                    @if($branchee)
                        <tr>
                            <th>Compte</th>
                            <td>
                                {{ $branchee->account_name ?: '—' }}
                                @if($branchee->last_synced_at)
                                    <span style="color:var(--encre-3);font-size:13px">
                                        — dernière collecte {{ $branchee->last_synced_at->diffForHumans() }}
                                    </span>
                                @endif
                                @if($branchee->last_error)
                                    <div class="erreur" style="display:block">{{ $branchee->last_error }}</div>
                                @endif
                            </td>
                        </tr>
                    @endif
                    </tbody>
                </table>
            </div>

            <div style="margin-top:16px">
                @if($branchee)
                    <div style="display:flex;gap:8px;flex-wrap:wrap">
                        @if($cle === 'ga4')
                            <form method="post" action="{{ route('analytique.collecter', $branchee) }}">
                                @csrf
                                <button type="submit" class="bouton bouton-accent">
                                    {{ $branchee->last_synced_at ? 'Collecter maintenant' : 'Collecter maintenant (90 derniers jours)' }}
                                </button>
                            </form>
                        @endif
                        @if($p['mode'] === 'oauth' && $configuree)
                            <a href="{{ route('connexion.rediriger', [$client, $fournisseur]) }}" class="bouton-fin">Reconnecter</a>
                        @endif
                        <form method="post" action="{{ route('integrations.destroy', $branchee) }}"
                              onsubmit="return confirm('Déconnecter ce compte ? Les statistiques déjà collectées restent en place.')">
                            @csrf @method('delete')
                            <button type="submit" class="bouton-fin">Déconnecter</button>
                        </form>
                    </div>

                @elseif($p['mode'] === 'compte_service')
                    {{-- GA4 : pas d'OAuth, un compte de service suffit --}}
                    <form method="post" action="{{ route('integrations.store', $client) }}">
                        @csrf
                        <input type="hidden" name="platform" value="{{ $cle }}">

                        <div class="champ">
                            <label for="property_id">Identifiant de la propriété GA4</label>
                            <input type="text" id="property_id" name="property_id"
                                   placeholder="ex. 397451234">
                            <div style="font-size:12px;color:var(--encre-3);margin-top:4px">
                                Dans Google Analytics : Admin → Paramètres de la propriété.
                            </div>
                        </div>

                        <div class="champ">
                            <label for="credentials">Clé du compte de service (fichier JSON)</label>
                            <textarea id="credentials" name="credentials" rows="4"
                                      placeholder='{"type":"service_account","client_email":"…","private_key":"…"}'></textarea>
                            @error('credentials') <div class="erreur" style="display:block">{{ $message }}</div> @enderror
                            <div style="font-size:12px;color:var(--encre-3);margin-top:4px">
                                N'oubliez pas d'ajouter l'adresse <code>client_email</code> du compte de service
                                comme <strong>Lecteur</strong> dans la propriété GA4, sinon Google refusera l'accès.
                            </div>
                        </div>

                        <button type="submit" class="bouton bouton-accent">Connecter</button>
                    </form>

                @elseif(! $configuree)
                    <div class="avis avis-garde" style="margin:0">
                        L'application développeur n'existe pas encore.
                        @if($cle === 'youtube')
                            Créez un projet sur <strong>Google Cloud Console</strong>, activez
                            <em>YouTube Data API v3</em>, puis transmettez le client ID et le secret OAuth.
                        @elseif(in_array($cle, ['facebook', 'instagram']))
                            Créez une application sur <strong>developers.facebook.com</strong> (type Business),
                            lancez la vérification d'entreprise, puis transmettez l'App ID et l'App Secret.
                        @else
                            Créez une application sur <strong>developers.tiktok.com</strong> et demandez
                            l'accès aux commentaires, puis transmettez la clé et le secret client.
                        @endif
                    </div>
                @else
                    <a href="{{ route('connexion.rediriger', [$client, $fournisseur]) }}" class="bouton bouton-accent" style="display:inline-block;width:auto">
                        Connecter {{ $p['nom'] }}
                    </a>
                    @if($fournisseur === 'meta')
                        <div style="font-size:12px;color:var(--encre-3);margin-top:6px">
                            Une seule connexion branche la Page Facebook et le compte Instagram qui lui est rattaché.
                        </div>
                    @endif
                @endif
            </div>
        </div>
    </div>
@endforeach
@endsection
