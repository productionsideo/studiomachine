<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>@yield('titre', 'Gestion') — Studio Machine</title>
    <link rel="stylesheet" href="{{ asset('assets/dashboard.css') }}">
</head>
<body>

@auth
<div class="app">
    <aside class="rail">
        <div class="rail-marque">
            <span class="rail-logo">SM</span>
            <span class="rail-nom">Gestion</span>
        </div>

        @php
            $c = request()->query('client');
            $q = $c ? ['client' => $c] : [];
        @endphp

        <nav class="rail-nav">
            <a href="{{ route('dashboard', $q) }}" @class(['on' => request()->routeIs('dashboard')])>
                Tableau de bord
            </a>
            <a href="{{ route('leads.index', $q) }}" @class(['on' => request()->routeIs('leads.*')])>
                Demandes
            </a>

            <div class="rail-sep">Publication</div>
            <a href="{{ route('calendrier.index', $q) }}" @class(['on' => request()->routeIs('calendrier.*', 'posts.*')])>
                Calendrier
            </a>
            <a href="{{ route('campaigns.index', $q) }}" @class(['on' => request()->routeIs('campaigns.*')])>
                Campagnes
            </a>
            @if($c || ! auth()->user()->isAdmin())
                <a href="{{ route('medias.index', $q) }}" @class(['on' => request()->routeIs('medias.*')])>
                    Médiathèque
                </a>
            @endif

            <div class="rail-sep">Suivi</div>
            @if($c || ! auth()->user()->isAdmin())
                <a href="{{ route('capsules.index', $q) }}" @class(['on' => request()->routeIs('capsules.*')])>
                    Capsules
                </a>
            @endif
            <a href="{{ route('comments.index', $q) }}" @class(['on' => request()->routeIs('comments.*')])>
                Commentaires
            </a>
            <a href="{{ route('assistant.index', $q) }}" @class(['on' => request()->routeIs('assistant.*')])>
                Assistant Claude
            </a>
            @if($c || ! auth()->user()->isAdmin())
                <a href="{{ route('integrations.index', $q) }}" @class(['on' => request()->routeIs('integrations.*')])>
                    Intégrations
                </a>
            @endif

            @if(auth()->user()->isAdmin())
                <div class="rail-sep">Administration</div>
                <a href="{{ route('clients.index') }}" @class(['on' => request()->routeIs('clients.*')])>
                    Clients
                </a>
                <a href="{{ route('users.index') }}" @class(['on' => request()->routeIs('users.*')])>
                    Accès
                </a>
            @endif
        </nav>

        <div class="rail-pied">
            <div class="rail-user">
                <strong>{{ auth()->user()->name }}</strong>
                <span>{{ auth()->user()->isAdmin() ? 'Studio Machine' : auth()->user()->client?->name }}</span>
            </div>
            <form method="post" action="{{ route('logout') }}">
                @csrf
                <button type="submit" class="lien-discret">Se déconnecter</button>
            </form>
        </div>
    </aside>

    <main class="vue">
        @if(session('ok'))
            <div class="avis avis-ok">{{ session('ok') }}</div>
        @endif

        @yield('contenu')
    </main>
</div>
@else
    @yield('contenu')
@endauth

</body>
</html>
