@extends('layouts.app')
@section('titre', 'Accès')

@section('contenu')
<div class="tete">
    <div>
        <h1>Accès</h1>
        <p class="sous">Qui peut entrer sur la plateforme, et jusqu’où.</p>
    </div>
</div>

@if(session('erreur'))
    <div class="avis avis-garde">{{ session('erreur') }}</div>
@endif

@if($errors->any())
    <div class="avis avis-garde">
        @foreach($errors->all() as $e) <div>{{ $e }}</div> @endforeach
    </div>
@endif

{{-- ---------------------------------------------------------------- --}}
{{-- Créer un accès                                                    --}}
{{-- ---------------------------------------------------------------- --}}

<div class="panneau">
    <div class="panneau-corps">
        <h2 style="font-size:16px;margin-bottom:4px">Créer un accès</h2>
        <p class="sous" style="margin-bottom:18px">
            Un <strong>administrateur</strong> voit tous les clients et peut créer d’autres accès.
            Un <strong>accès client</strong> ne voit que les données de son entreprise — c’est ce
            cloisonnement qui garantit qu’un client n’aperçoit jamais les demandes d’un autre.
        </p>

        <form method="post" action="{{ route('users.store') }}" class="grille-form">
            @csrf

            <div class="champ">
                <label for="name">Nom</label>
                <input type="text" id="name" name="name" value="{{ old('name') }}" required maxlength="120">
            </div>

            <div class="champ">
                <label for="email">Courriel</label>
                <input type="email" id="email" name="email" value="{{ old('email') }}" required maxlength="255">
            </div>

            <div class="champ">
                <label for="role">Rôle</label>
                <select id="role" name="role" required onchange="document.getElementById('bloc-client').hidden = (this.value !== 'client')">
                    <option value="admin"  @selected(old('role') === 'admin')>Administrateur — Studio Machine</option>
                    <option value="client" @selected(old('role', 'client') === 'client')>Accès client</option>
                </select>
            </div>

            <div class="champ" id="bloc-client" @if(old('role') === 'admin') hidden @endif>
                <label for="client_id">Entreprise</label>
                <select id="client_id" name="client_id">
                    <option value="">— choisir —</option>
                    @foreach($clients as $c)
                        <option value="{{ $c->id }}" @selected(old('client_id') == $c->id)>{{ $c->name }}</option>
                    @endforeach
                </select>
            </div>

            <div class="champ">
                <label for="password">Mot de passe <span style="font-weight:400;color:var(--encre-3)">— 12 caractères minimum</span></label>
                <input type="password" id="password" name="password" required autocomplete="new-password">
            </div>

            <div class="champ">
                <label for="password_confirmation">Répéter le mot de passe</label>
                <input type="password" id="password_confirmation" name="password_confirmation" required autocomplete="new-password">
            </div>

            <div class="champ" style="align-self:end">
                <button type="submit" class="bouton bouton-accent">Créer l’accès</button>
            </div>
        </form>
    </div>
</div>

{{-- ---------------------------------------------------------------- --}}
{{-- Les administrateurs                                               --}}
{{-- ---------------------------------------------------------------- --}}

<div class="panneau">
    <div class="panneau-corps" style="padding-bottom:0">
        <h2 style="font-size:16px">Administrateurs — Studio Machine</h2>
        <p class="sous">Ils voient tous les clients.</p>
    </div>

    <div class="tableau-cadre">
        <table>
            <thead>
            <tr><th>Nom</th><th>Courriel</th><th>Dernière entrée</th><th>État</th><th></th></tr>
            </thead>
            <tbody>
            @forelse($admins as $u)
                <tr>
                    <td>
                        <strong>{{ $u->name }}</strong>
                        @if($u->id === auth()->id())
                            <span style="color:var(--encre-3);font-size:12px"> — vous</span>
                        @endif
                    </td>
                    <td style="font-size:13px">{{ $u->email }}</td>
                    <td style="font-size:13px;color:var(--encre-3)">
                        {{ $u->last_login_at?->diffForHumans() ?? 'jamais' }}
                    </td>
                    <td>
                        <span class="pastille {{ $u->active ? 'pastille-gagne' : 'pastille-perdu' }}">
                            {{ $u->active ? 'actif' : 'suspendu' }}
                        </span>
                    </td>
                    <td class="num">
                        @include('dashboard.partials.acces-actions', ['u' => $u])
                    </td>
                </tr>
            @empty
                <tr><td colspan="5" class="vide">Aucun administrateur.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>

{{-- ---------------------------------------------------------------- --}}
{{-- Les accès clients                                                 --}}
{{-- ---------------------------------------------------------------- --}}

<div class="panneau">
    <div class="panneau-corps" style="padding-bottom:0">
        <h2 style="font-size:16px">Accès clients</h2>
        <p class="sous">Chacun ne voit que son entreprise.</p>
    </div>

    <div class="tableau-cadre">
        <table>
            <thead>
            <tr><th>Nom</th><th>Entreprise</th><th>Courriel</th><th>Dernière entrée</th><th>État</th><th></th></tr>
            </thead>
            <tbody>
            @forelse($membres as $u)
                <tr>
                    <td><strong>{{ $u->name }}</strong></td>
                    <td>{{ $u->client?->name ?? '—' }}</td>
                    <td style="font-size:13px">{{ $u->email }}</td>
                    <td style="font-size:13px;color:var(--encre-3)">
                        {{ $u->last_login_at?->diffForHumans() ?? 'jamais' }}
                    </td>
                    <td>
                        <span class="pastille {{ $u->active ? 'pastille-gagne' : 'pastille-perdu' }}">
                            {{ $u->active ? 'actif' : 'suspendu' }}
                        </span>
                    </td>
                    <td class="num">
                        @include('dashboard.partials.acces-actions', ['u' => $u])
                    </td>
                </tr>
            @empty
                <tr><td colspan="6" class="vide">Aucun accès client.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection
