@extends('layouts.app')
@section('titre', 'Résumé — ' . $client->name)
@section('classe-vue', 'pleine')

@php
    use App\Http\Controllers\ResumeController as R;
    use App\Services\Publication\Reseaux;
    use Illuminate\Support\Carbon;
    use Illuminate\Support\Str;

    $admin = auth()->user()->isAdmin();
    $base  = ['client' => $client->slug, 'jours' => $jours];
    $nb    = fn ($v) => $v === null ? '—' : number_format($v, 0, ',', ' ');
    $commun = ['comptes' => $comptes, 'collectes' => $collectes, 'dates' => $dates];
    $tri   = request('tri', 'publie_le');
@endphp

@section('contenu')
<div class="tete">
    <div>
        <h1>Résumé</h1>
        <p class="sous">{{ $client->name }} — {{ $jours }} derniers jours, tous réseaux.
            @if($derniere) Mis à jour {{ Carbon::parse($derniere)->diffForHumans() }}. @endif
        </p>
    </div>
    <div class="outils">
        @if($clients->isNotEmpty())
            <select onchange="location = this.value" style="width:auto">
                @foreach($clients as $c)
                    <option value="{{ route('resume.index', ['client' => $c->slug, 'jours' => $jours]) }}" @selected($c->id === $client->id)>{{ $c->name }}</option>
                @endforeach
            </select>
        @endif
        <span class="bascule">
            @foreach([7 => '7 j', 30 => '30 j', 90 => '90 j'] as $d => $l)
                <a @class(['bouton-fin', 'on' => $jours === $d]) href="{{ route('resume.index', ['client' => $client->slug, 'jours' => $d]) }}">{{ $l }}</a>
            @endforeach
        </span>
        @if($admin)
            <form method="post" action="{{ route('resume.collecter', $client) }}">
                @csrf
                <button type="submit" class="bouton-fin">Mettre à jour maintenant</button>
            </form>
        @endif
    </div>
</div>

@if($comptes->isEmpty())
    <div class="avis avis-info">
        Aucun réseau n’est connecté pour {{ $client->name }}.
        <a href="{{ route('integrations.index', ['client' => $client->slug]) }}"><u>Connecter un compte</u></a>.
    </div>
@elseif(! $derniere)
    <div class="avis avis-info">
        Les statistiques n’ont pas encore été collectées pour ce client. La collecte passe toutes les 6 heures
        @if($admin) — ou cliquez sur « Mettre à jour maintenant »@endif.
        Facebook et Instagram rapportent jusqu’à 30 jours d’historique ; YouTube ne donne que des totaux,
        ses courbes se dessinent à partir de la première collecte.
    </div>
@endif

{{-- ===================== Compte ===================== --}}
<h2 class="section-resume">Compte</h2>
<div class="panneau resume-panneau">
    @include('resume._bloc', $commun + ['titre' => 'Abonnés', 'series' => $abonnes, 'mode' => 'dernier', 'zero' => false, 'unite' => 'abonnés'])
    @include('resume._bloc', $commun + ['titre' => 'Vues', 'series' => $vues, 'prec' => $vuesPrec, 'mode' => 'somme', 'zero' => true, 'unite' => 'vues'])
</div>

{{-- ===================== Publications ===================== --}}
<h2 class="section-resume">Publications</h2>
<div class="panneau resume-panneau">
    @include('resume._bloc', $commun + ['titre' => 'Interactions', 'series' => $interactions, 'prec' => array_map(fn ($t) => $t[1], $tendances['interactions']), 'mode' => 'somme', 'zero' => true, 'unite' => 'interactions'])
    @include('resume._bloc', $commun + ['titre' => 'Nombre de publications', 'series' => $nombre, 'prec' => array_map(fn ($t) => $t[1], $tendances['nombre']), 'mode' => 'somme', 'zero' => true, 'unite' => 'publications', 'barres' => true])

    <div class="bloc">
        <div class="liste-tete">
            <h3>Liste des publications</h3>
            <form method="get" action="{{ route('resume.index') }}" class="liste-filtres">
                <input type="hidden" name="client" value="{{ $client->slug }}">
                <input type="hidden" name="jours" value="{{ $jours }}">
                <input type="search" name="q" value="{{ request('q') }}" placeholder="Rechercher dans le texte">
                <select name="reseau" onchange="this.form.submit()" style="width:auto">
                    <option value="">Tous les réseaux</option>
                    @foreach($comptes->whereIn('platform', $collectes) as $c)
                        <option value="{{ $c->platform }}" @selected(request('reseau') === $c->platform)>{{ Reseaux::nom($c->platform) }}</option>
                    @endforeach
                </select>
                <select name="tri" onchange="this.form.submit()" style="width:auto">
                    @foreach(['publie_le' => 'Plus récentes', 'vues' => 'Plus vues', 'interactions' => 'Plus d’interactions'] as $v => $l)
                        <option value="{{ $v }}" @selected($tri === $v)>{{ $l }}</option>
                    @endforeach
                </select>
                <a class="bouton-fin" href="{{ route('resume.exporter', request()->only('q', 'reseau', 'tri') + $base) }}">Télécharger en CSV</a>
            </form>
        </div>

        @if($liste->isEmpty())
            <p class="vide">Aucune publication sur la période.</p>
        @else
            <div class="tableau-cadre">
                <table class="liste-publications">
                    <thead><tr>
                        <th>Publication</th><th>Réseau</th><th>Type</th><th>Date</th>
                        <th class="num">Vues</th><th class="num">Portée</th><th class="num">Interactions</th>
                        <th class="num">J’aime</th><th class="num">Commentaires</th><th class="num">Partages</th>
                    </tr></thead>
                    <tbody>
                    @foreach($liste as $p)
                        <tr>
                            <td>
                                <a class="pub-apercu" href="{{ $p->lien }}" target="_blank" rel="noopener">
                                    @if($p->vignette) <img src="{{ $p->vignette }}" alt="" loading="lazy" referrerpolicy="no-referrer"> @else <span class="sans-vignette"></span> @endif
                                    <span>{{ Str::limit($p->texte, 90) ?: 'Sans texte' }}</span>
                                </a>
                            </td>
                            <td>@include('publication._reseau', ['reseau' => $p->platform])</td>
                            <td>{{ ucfirst($p->type) }}</td>
                            <td style="white-space:nowrap">{{ Carbon::parse($p->publie_le, 'UTC')->setTimezone(config('publication.fuseau'))->translatedFormat('j M Y · H:i') }}</td>
                            <td class="num">{{ $nb($p->vues) }}</td>
                            <td class="num">{{ $nb($p->portee) }}</td>
                            <td class="num"><strong>{{ $nb($p->interactions) }}</strong></td>
                            <td class="num">{{ $nb($p->j_aime) }}</td>
                            <td class="num">{{ $nb($p->commentaires) }}</td>
                            <td class="num">{{ $nb($p->partages) }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
            <div class="pagination-resume">
                <span>{{ $liste->firstItem() }}–{{ $liste->lastItem() }} sur {{ $liste->total() }}</span>
                @if($liste->previousPageUrl()) <a class="bouton-fin" href="{{ $liste->previousPageUrl() }}">←</a> @endif
                @if($liste->nextPageUrl()) <a class="bouton-fin" href="{{ $liste->nextPageUrl() }}">→</a> @endif
            </div>
        @endif
    </div>
</div>

{{-- ===================== Comptes publicitaires ===================== --}}
<h2 class="section-resume">Comptes publicitaires</h2>
<div class="panneau resume-panneau">
    <div class="bloc">
        <div class="attente-pub">
            <strong>Meta Ads — à connecter.</strong>
            Impressions, clics, CPM, CPC et dépenses des campagnes payantes s’afficheront ici. Il faut pour cela
            la permission <code>ads_read</code> sur l’application Meta « Studio Machine » et l’identifiant du compte
            publicitaire de {{ $client->name }}.
        </div>
    </div>
</div>

<script>
(() => {
    // Infobulle des courbes : la date et la valeur du jour survolé (et le détail par réseau pour un total).
    const nb = v => v === null ? '—' : new Intl.NumberFormat('fr-CA').format(v);
    const jour = d => new Intl.DateTimeFormat('fr-CA', { day: 'numeric', month: 'short', year: 'numeric', timeZone: 'UTC' }).format(new Date(d + 'T12:00:00Z'));

    document.querySelectorAll('.courbe').forEach(c => {
        const zone = c.querySelector('.courbe-zone');
        if (!zone) return;
        const dates = JSON.parse(c.dataset.dates), vals = JSON.parse(c.dataset.valeurs);
        const detail = c.dataset.detail ? JSON.parse(c.dataset.detail) : null;
        const curseur = zone.querySelector('.curseur'), bulle = zone.querySelector('.infobulle');

        zone.addEventListener('mousemove', e => {
            const r = zone.getBoundingClientRect();
            const barres = !!c.dataset.barres, n = dates.length;
            const f = (e.clientX - r.left) / r.width;
            const i = Math.max(0, Math.min(n - 1, barres ? Math.floor(f * n) : Math.round(f * (n - 1))));
            const x = barres ? (i + 0.5) / n * 100 : (n > 1 ? i / (n - 1) * 100 : 50);
            curseur.hidden = false; bulle.hidden = false;
            curseur.style.left = x + '%';
            const unite = vals[i] !== null && vals[i] <= 1 ? c.dataset.nom.replace(/s$/, '') : c.dataset.nom;
            let html = `<strong>${jour(dates[i])}</strong><br>${nb(vals[i])} ${unite}`;
            if (detail && detail[dates[i]]) {
                html += '<br>' + Object.entries(detail[dates[i]]).map(([k, v]) => `<span class="detail">${k} : ${nb(v)}</span>`).join('<br>');
            }
            bulle.innerHTML = html;
            bulle.style.left = x + '%';
            bulle.classList.toggle('gauche', x > 70);
        });
        zone.addEventListener('mouseleave', () => { curseur.hidden = true; bulle.hidden = true; });
    });
})();
</script>
@endsection
