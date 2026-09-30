@extends('layouts.app')
@section('titre', $client->name)

@section('contenu')
<div class="tete">
    <div>
        <h1>{{ $client->name }}</h1>
        <p class="sous">
            @if($client->website)
                <a href="{{ $client->website }}" target="_blank" rel="noopener">{{ parse_url($client->website, PHP_URL_HOST) }}</a> —
            @endif
            {{ $days }} derniers jours
        </p>
    </div>
    <div class="outils">
        @if($clients->isNotEmpty())
            <select onchange="location = this.value">
                <option value="{{ route('dashboard', ['jours' => $days]) }}">Tous les clients</option>
                @foreach($clients as $c)
                    <option value="{{ route('dashboard', ['client' => $c->slug, 'jours' => $days]) }}"
                            @selected($c->id === $client->id)>{{ $c->name }}</option>
                @endforeach
            </select>
        @endif
        @foreach([7 => '7 j', 30 => '30 j', 90 => '90 j', 365 => '1 an'] as $d => $label)
            <a href="{{ route('dashboard', ['client' => $client->slug, 'jours' => $d]) }}"
               @class(['bouton-fin', 'on' => $days === $d])>{{ $label }}</a>
        @endforeach
    </div>
</div>

{{-- L'entonnoir --}}
<div class="chiffres">
    <div class="chiffre">
        <div class="etiquette">Visites</div>
        <div class="valeur">{{ number_format($stats['visits'], 0, ',', ' ') }}</div>
        <div class="note">Clics depuis les capsules</div>
    </div>
    <div class="chiffre">
        <div class="etiquette">Formulaires commencés</div>
        <div class="valeur">{{ number_format($stats['starts'], 0, ',', ' ') }}</div>
        <div class="note">{{ number_format($stats['completion'], 1, ',', ' ') }}&nbsp;% vont au bout</div>
    </div>
    <div class="chiffre fort">
        <div class="etiquette">Demandes reçues</div>
        <div class="valeur">{{ number_format($stats['submits'], 0, ',', ' ') }}</div>
        <div class="note">Formulaires complétés</div>
    </div>
    <div class="chiffre">
        <div class="etiquette">Taux de conversion</div>
        <div class="valeur">{{ number_format($stats['conversion'], 1, ',', ' ') }}&nbsp;%</div>
        <div class="note">Sur 100 visiteurs</div>
    </div>
</div>

<div class="grille grille-2">
    {{-- Courbe --}}
    <div class="panneau">
        <h2>
            Activité
            <span class="aide">visites et demandes, jour par jour</span>
        </h2>
        <div class="panneau-corps">
            @php
                $max = max(1, collect($daily)->max('visits'));
            @endphp
            <div class="courbe">
                @foreach($daily as $j)
                    @php
                        $hv = round($j['visits']  / $max * 150);
                        $hs = round($j['submits'] / $max * 150);
                    @endphp
                    <div class="courbe-jour"
                         title="{{ \Carbon\Carbon::parse($j['date'])->translatedFormat('j M') }} — {{ $j['visits'] }} visite(s), {{ $j['submits'] }} demande(s)">
                        @if($j['submits'] > 0)
                            <div class="courbe-envoi" style="height:{{ max(3, $hs) }}px"></div>
                        @endif
                        <div class="courbe-visite" style="height:{{ $hv }}px"></div>
                    </div>
                @endforeach
            </div>
            <div class="legende">
                <span><i class="carre" style="background:#d8dce3"></i> Visites</span>
                <span><i class="carre" style="background:var(--accent)"></i> Demandes</span>
            </div>
        </div>
    </div>

    {{-- Réseaux --}}
    <div class="panneau">
        <h2>D'où viennent les visites</h2>
        <div class="tableau-cadre">
            <table>
                <thead>
                <tr><th>Réseau</th><th class="num">Visites</th><th class="num">Demandes</th></tr>
                </thead>
                <tbody>
                @forelse($byPlatform as $reseau => $v)
                    <tr>
                        <td>
                            <span class="reseau">
                                <i class="point point-{{ $reseau }}"></i>
                                {{ ucfirst($reseau) }}
                            </span>
                        </td>
                        <td class="num">{{ $v['visits'] }}</td>
                        <td class="num"><strong>{{ $v['submits'] }}</strong></td>
                    </tr>
                @empty
                    <tr><td colspan="3" class="vide">Aucune visite pour l'instant.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="grille grille-2" style="margin-top:16px">
    {{-- Top capsules --}}
    <div class="panneau">
        <h2>
            Capsules qui rapportent
            <a class="aide" href="{{ route('capsules.index', ['client' => $client->slug, 'jours' => $days]) }}">
                voir les 72 →
            </a>
        </h2>
        <div class="tableau-cadre">
            <table>
                <thead>
                <tr>
                    <th>Capsule</th>
                    <th class="num">Visites</th>
                    <th class="num">Demandes</th>
                    <th class="num">Conversion</th>
                </tr>
                </thead>
                <tbody>
                @forelse($topCapsules as $c)
                    <tr onclick="location='{{ route('capsules.show', [$c['id'], 'jours' => $days]) }}'" style="cursor:pointer">
                        <td>
                            <strong>#{{ $c['number'] }}</strong>
                            <span style="color:var(--encre-3)">{{ $c['title'] }}</span>
                        </td>
                        <td class="num">{{ $c['visits'] }}</td>
                        <td class="num"><strong>{{ $c['submits'] }}</strong></td>
                        <td class="num">{{ number_format($c['conversion'], 1, ',', ' ') }}&nbsp;%</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="4" class="vide">
                            Aucune capsule n'a encore généré de visite.<br>
                            <span style="font-size:13px">Les capsules apparaissent dès le premier clic sur leur lien.</span>
                        </td>
                    </tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div>
        {{-- Appareils --}}
        <div class="panneau" style="margin-bottom:16px">
            <h2>Appareils</h2>
            <div class="panneau-corps">
                @php $totalApp = max(1, array_sum($byDevice)); @endphp
                @forelse($byDevice as $appareil => $n)
                    <div style="margin-bottom:12px">
                        <div style="display:flex;justify-content:space-between;font-size:13px;margin-bottom:4px">
                            <span>{{ ucfirst($appareil) }}</span>
                            <strong>{{ round($n / $totalApp * 100) }}&nbsp;%</strong>
                        </div>
                        <div class="jauge-rail">
                            <div class="jauge-part" style="width:{{ $n / $totalApp * 100 }}%"></div>
                        </div>
                    </div>
                @empty
                    <p style="color:var(--encre-3);font-size:14px">Aucune donnée.</p>
                @endforelse
            </div>
        </div>

        {{-- Abandons --}}
        <div class="panneau">
            <h2>
                Abandons
                <span class="aide">où ils lâchent</span>
            </h2>
            <div class="panneau-corps">
                @php
                    $etapes = [
                        1 => 'Étape 1 — Le développement',
                        2 => 'Étape 2 — La maison',
                        3 => 'Étape 3 — Le budget',
                        4 => 'Étape 4 — Les coordonnées',
                    ];
                @endphp
                @forelse($dropoff as $etape => $n)
                    <div style="display:flex;justify-content:space-between;font-size:13px;padding:6px 0;border-bottom:1px solid var(--trait)">
                        <span>{{ $etapes[$etape] ?? "Étape {$etape}" }}</span>
                        <strong>{{ $n }}</strong>
                    </div>
                @empty
                    <p style="color:var(--encre-3);font-size:14px">Aucun abandon enregistré.</p>
                @endforelse
            </div>
        </div>
    </div>
</div>

{{-- Dernières demandes --}}
<div class="panneau" style="margin-top:16px">
    <h2>
        Dernières demandes
        <a class="aide" href="{{ route('leads.index', ['client' => $client->slug]) }}">tout voir →</a>
    </h2>
    <div class="tableau-cadre">
        <table>
            <thead>
            <tr>
                <th>Reçue</th><th>Nom</th><th>Téléphone</th>
                <th>Terrain</th><th>Échéancier</th><th>Capsule</th><th>Statut</th>
            </tr>
            </thead>
            <tbody>
            @forelse($recentLeads as $l)
                <tr onclick="location='{{ route('leads.show', $l) }}'" style="cursor:pointer">
                    <td style="white-space:nowrap;color:var(--encre-3)">
                        {{ optional($l->submitted_at)->translatedFormat('j M, H:i') }}
                    </td>
                    <td><strong>{{ $l->name }}</strong></td>
                    <td>{{ $l->phone ?: '—' }}</td>
                    <td>{{ $l->has_land ?: '—' }}</td>
                    <td>{{ $l->timeline ?: '—' }}</td>
                    <td>{{ $l->capsule ? '#'.$l->capsule->number : '—' }}</td>
                    <td><span class="pastille pastille-{{ $l->status }}">{{ $l->status }}</span></td>
                </tr>
            @empty
                <tr><td colspan="7" class="vide">Aucune demande reçue pour l'instant.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection
