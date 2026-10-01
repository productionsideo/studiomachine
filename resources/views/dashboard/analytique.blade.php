@extends('layouts.app')
@section('titre', 'Analytique')

@php
    $n   = fn ($v) => number_format((float) $v, 0, ',', ' ');
    $pct = fn ($a, $b) => $b ? number_format($a / $b * 100, 1, ',', ' ') . ' %' : '—';
    $max = max(1, max($serie ?: [0]));
@endphp

@section('contenu')
<div class="tete">
    <div>
        <h1>Analytique</h1>
        <p class="sous">{{ $client->name }} — Google Analytics 4, {{ $jours }} derniers jours.</p>
    </div>
    <div class="outils">
        @foreach([7 => '7 j', 30 => '30 j', 90 => '90 j', 365 => '1 an'] as $d => $label)
            <a href="{{ route('analytique.index', ['client' => $client->slug, 'jours' => $d]) }}"
               @class(['bouton-fin', 'on' => $jours === $d])>{{ $label }}</a>
        @endforeach
    </div>
</div>

@if(! $ga4)
    <div class="avis avis-info">
        Aucune propriété GA4 n’est branchée pour {{ $client->name }}.
        <a href="{{ route('integrations.index', ['client' => $client->slug]) }}"><u>La brancher dans Intégrations</u></a>.
    </div>
@else
    @if($ga4->last_error)
        <div class="avis avis-garde"><strong>Dernière collecte refusée :</strong> {{ $ga4->last_error }}</div>
    @elseif(! $ga4->last_synced_at)
        <div class="avis avis-info">
            Propriété branchée, jamais collectée. Lancez une première collecte depuis
            <a href="{{ route('integrations.index', ['client' => $client->slug]) }}"><u>Intégrations</u></a>
            (90 jours d’historique), ou attendez la collecte automatique (toutes les 3 heures).
        </div>
    @else
        <p class="aide-champ" style="margin:-12px 0 16px">Dernière collecte {{ $ga4->last_synced_at->diffForHumans() }} · propriété {{ $ga4->settings['property_id'] ?? '—' }} · les 48 dernières heures peuvent encore bouger.</p>
    @endif

    <div class="chiffres">
        <div class="chiffre"><div class="etiquette">Sessions</div><div class="valeur">{{ $n($totaux->s) }}</div>
            <div class="note">{{ $n($totaux->u) }} utilisateurs</div></div>
        <div class="chiffre"><div class="etiquette">Engagement</div><div class="valeur">{{ $pct($totaux->e, $totaux->s) }}</div>
            <div class="note">sessions engagées</div></div>
        <div class="chiffre"><div class="etiquette">Clics vers Amazon</div><div class="valeur">{{ $n($clics) }}</div>
            <div class="note">liens sortants amazon.*</div></div>
        <div class="chiffre fort"><div class="etiquette">Achats</div><div class="valeur">{{ $n($totaux->a) }}</div>
            <div class="note">{{ $totaux->r > 0 ? number_format($totaux->r, 2, ',', ' ') . ' $ de revenus' : 'sur le site (hors Amazon)' }}</div></div>
    </div>

    <div class="panneau" style="margin-bottom:16px">
        <h2>Sessions par jour</h2>
        <div class="panneau-corps">
            <div class="courbe">
                @foreach($serie as $jour => $s)
                    <div class="courbe-jour" title="{{ $jour }} : {{ $s }} session(s)">
                        <div class="courbe-envoi" style="height:{{ $s / $max * 100 }}%"></div>
                    </div>
                @endforeach
            </div>
        </div>
    </div>

    <div class="grille grille-2">
        <div class="panneau">
            <h2>Provenance <span class="aide">source / support</span></h2>
            <div class="tableau-cadre">
                <table>
                    <thead><tr><th>Source / support</th><th class="num">Sessions</th><th class="num">Engag.</th><th class="num">Amazon</th><th class="num">Achats</th></tr></thead>
                    <tbody>
                    @forelse($parSource as $l)
                        <tr>
                            <td>{{ $l->source }} <span style="color:var(--encre-3)">/ {{ $l->support }}</span></td>
                            <td class="num">{{ $n($l->s) }}</td>
                            <td class="num">{{ $pct($l->e, $l->s) }}</td>
                            <td class="num">{{ $n($clicsParSource[$l->source] ?? 0) }}</td>
                            <td class="num">{{ $n($l->a) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="vide">Aucune donnée sur la période.</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        <div class="panneau">
            <h2>Campagnes <span class="aide">utm_campaign</span></h2>
            <div class="tableau-cadre">
                <table>
                    <thead><tr><th>Campagne</th><th class="num">Sessions</th><th class="num">Amazon</th><th class="num">Achats</th></tr></thead>
                    <tbody>
                    @forelse($parCampagne as $l)
                        <tr>
                            <td>
                                @if(isset($nos[$l->campagne]))
                                    <a href="{{ route('campaigns.show', $nos[$l->campagne]) }}"><u>{{ $l->campagne }}</u></a>
                                @else
                                    {{ $l->campagne }}
                                @endif
                            </td>
                            <td class="num">{{ $n($l->s) }}</td>
                            <td class="num">{{ $n($clicsParCampagne[$l->campagne] ?? 0) }}</td>
                            <td class="num">{{ $n($l->a) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="vide">Aucune visite marquée d’une campagne sur la période.</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
@endif
@endsection
