@extends('layouts.app')
@section('titre', 'Capsule #'.$capsule->number)

@section('contenu')
<div class="tete">
    <div>
        <p class="sous">
            <a href="{{ route('capsules.index', ['client' => $client->slug, 'jours' => $days]) }}">← Capsules</a>
        </p>
        <h1>Capsule #{{ $capsule->number }}</h1>
        <p class="sous">{{ $capsule->title }} — {{ $days }} derniers jours</p>
    </div>
</div>

<div class="grille grille-2">
    <div class="panneau">
        <h2>Performance par réseau</h2>
        <div class="tableau-cadre">
            <table>
                <thead>
                <tr>
                    <th>Réseau</th>
                    <th class="num">Visites</th>
                    <th class="num">Demandes</th>
                    <th class="num">Conversion</th>
                </tr>
                </thead>
                <tbody>
                @forelse($byPlatform as $p)
                    @php $conv = $p->visits > 0 ? round($p->submits / $p->visits * 100, 1) : 0; @endphp
                    <tr>
                        <td>
                            <span class="reseau">
                                <i class="point point-{{ $p->platform }}"></i>{{ ucfirst($p->platform) }}
                            </span>
                        </td>
                        <td class="num">{{ $p->visits }}</td>
                        <td class="num"><strong>{{ $p->submits }}</strong></td>
                        <td class="num">{{ number_format($conv, 1, ',', ' ') }}&nbsp;%</td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="vide">Aucune visite depuis cette capsule.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="panneau">
        <h2>Publications</h2>
        <div class="tableau-cadre">
            <table>
                <thead>
                <tr><th>Réseau</th><th class="num">Vues</th><th class="num">Commentaires</th></tr>
                </thead>
                <tbody>
                @forelse($capsule->posts as $post)
                    <tr>
                        <td>
                            <span class="reseau">
                                <i class="point point-{{ $post->platform }}"></i>
                                @if($post->post_url)
                                    <a href="{{ $post->post_url }}" target="_blank" rel="noopener">{{ ucfirst($post->platform) }} ↗</a>
                                @else
                                    {{ ucfirst($post->platform) }}
                                @endif
                            </span>
                        </td>
                        <td class="num">
                            {{ $post->latestMetric ? number_format($post->latestMetric->views, 0, ',', ' ') : '—' }}
                        </td>
                        <td class="num">
                            {{ $post->latestMetric ? $post->latestMetric->comments_count : '—' }}
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="3" class="vide">
                            Aucune publication rattachée.<br>
                            <span style="font-size:13px">Elles apparaîtront une fois les réseaux connectés.</span>
                        </td>
                    </tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="panneau" style="margin-top:16px">
    <h2>Demandes issues de cette capsule</h2>
    <div class="tableau-cadre">
        <table>
            <thead>
            <tr><th>Reçue</th><th>Nom</th><th>Téléphone</th><th>Réseau</th><th>Statut</th></tr>
            </thead>
            <tbody>
            @forelse($leads as $l)
                <tr onclick="location='{{ route('leads.show', $l) }}'" style="cursor:pointer">
                    <td style="color:var(--encre-3);white-space:nowrap">
                        {{ optional($l->submitted_at)->translatedFormat('j M Y') }}
                    </td>
                    <td><strong>{{ $l->name }}</strong></td>
                    <td>{{ $l->phone ?: '—' }}</td>
                    <td>
                        @if($l->platform)
                            <span class="reseau"><i class="point point-{{ $l->platform }}"></i>{{ ucfirst($l->platform) }}</span>
                        @else — @endif
                    </td>
                    <td><span class="pastille pastille-{{ $l->status }}">{{ $l->status }}</span></td>
                </tr>
            @empty
                <tr><td colspan="5" class="vide">Aucune demande issue de cette capsule.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection
