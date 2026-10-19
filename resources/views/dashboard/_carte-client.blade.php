{{-- Un client : ses chiffres, ses comptes connectés, et un raccourci vers chacune de ses pages. --}}
@php
    $c = $k['client'];
    $q = ['client' => $c->slug];
    $nb = fn ($v) => number_format($v, 0, ',', ' ');
@endphp
<div class="carte-client">
    <div class="carte-tete">
        <a class="nom" href="{{ route('dashboard', $q + ['jours' => $days ?? 30]) }}">{{ $c->name }}</a>
        @if(auth()->user()->isAdmin())
            <a class="lien-discret" href="{{ route('clients.edit', $c) }}">Paramètres</a>
        @endif
    </div>
    <div class="carte-sous">
        @if($c->website) {{ parse_url($c->website, PHP_URL_HOST) }} · @endif
        {{ $c->campaigns_count }} campagne{{ $c->campaigns_count > 1 ? 's' : '' }}
    </div>

    <div class="carte-chiffres">
        <div><span class="v">{{ $nb($k['visites']) }}</span><span class="e">visites</span></div>
        <div class="fort"><span class="v">{{ $nb($k['demandes']) }}</span><span class="e">demandes</span></div>
        <div><span class="v">{{ $nb($k['publiees']) }}</span><span class="e">publiées</span></div>
        <div><span class="v">{{ $nb($k['a_venir']) }}</span><span class="e">à venir</span></div>
    </div>

    <div class="carte-pied">
        @forelse($k['reseaux'] as $reseau)
            @include('publication._reseau', ['reseau' => $reseau])
        @empty
            <span class="muet">aucun réseau connecté</span>
        @endforelse
        @if($k['erreurs'])
            <a class="alerte-compte" href="{{ route('integrations.index', $q) }}">{{ $k['erreurs'] }} compte{{ $k['erreurs'] > 1 ? 's' : '' }} à reconnecter</a>
        @endif
    </div>

    <nav class="raccourcis">
        <a href="{{ route('dashboard', $q) }}">Tableau de bord</a>
        <a href="{{ route('resume.index', $q) }}">Résumé réseaux</a>
        <a href="{{ route('calendrier.index', $q) }}">Calendrier</a>
        <a href="{{ route('campaigns.index', $q) }}">Campagnes</a>
        <a href="{{ route('leads.index', $q) }}">Demandes</a>
        <a href="{{ route('integrations.index', $q) }}">Intégrations</a>
    </nav>
</div>
