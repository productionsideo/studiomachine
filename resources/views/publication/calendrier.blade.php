@extends('layouts.app')
@section('titre', 'Calendrier')

@php
    use App\Services\Publication\Reseaux;
    $base = $client ? ['client' => $client->slug] : [];
    $prec = $mois->copy()->subMonth()->format('Y-m');
    $suiv = $mois->copy()->addMonth()->format('Y-m');
    $admin = auth()->user()->isAdmin();
@endphp

@section('contenu')
<div class="tete">
    <div>
        <h1>{{ ucfirst($mois->translatedFormat('F Y')) }}</h1>
        <p class="sous">{{ $client?->name ?? 'Tous les clients' }} — heure de Montréal.</p>
    </div>
    <div class="outils">
        @if($admin)
            <form method="get" action="{{ route('calendrier.index') }}">
                <input type="hidden" name="mois" value="{{ $mois->format('Y-m') }}">
                <select name="client" onchange="this.form.submit()" style="width:auto">
                    <option value="">Tous les clients</option>
                    @foreach($clients as $c)
                        <option value="{{ $c->slug }}" @selected($client?->id === $c->id)>{{ $c->name }}</option>
                    @endforeach
                </select>
            </form>
        @endif
        <a class="bouton-fin" href="{{ route('calendrier.index', $base + ['mois' => $prec]) }}">←</a>
        <a class="bouton-fin" href="{{ route('calendrier.index', $base) }}">Aujourd’hui</a>
        <a class="bouton-fin" href="{{ route('calendrier.index', $base + ['mois' => $suiv]) }}">→</a>
        @if($admin)
            <a class="bouton bouton-accent" href="{{ route('posts.create', $base) }}">Nouvelle publication</a>
        @endif
    </div>
</div>

<div class="panneau" style="margin-bottom:18px">
    <div class="cal">
        @foreach(['Lun', 'Mar', 'Mer', 'Jeu', 'Ven', 'Sam', 'Dim'] as $j)
            <div class="cal-entete">{{ $j }}</div>
        @endforeach

        @foreach($jours as $jour)
            @php $cle = $jour->toDateString(); @endphp
            <div @class(['cal-jour', 'hors' => $jour->month !== $mois->month, 'auj' => $cle === $aujourdhui])>
                <div class="cal-tete-jour">
                    <span class="cal-num">{{ $jour->day }}</span>
                    @if($admin && $cle >= $aujourdhui)
                        <a class="cal-ajout" title="Programmer ce jour-là"
                           href="{{ route('posts.create', $base + ['jour' => $cle]) }}">+</a>
                    @endif
                </div>

                @foreach($posts[$cle] ?? [] as $p)
                    <a href="{{ route('posts.show', $p) }}"
                       @class(['cal-item', 'echec' => in_array($p->status, ['echec', 'partielle']), 'publiee' => $p->status === 'publiee'])
                       style="--couleur: {{ $p->campaign?->color ?? $p->client->accent_color }}"
                       title="{{ \App\Models\Post::STATUTS[$p->status] }}">
                        <span class="h">{{ $p->heureLocale()->format('H:i') }}</span>
                        <span class="pts">
                            @foreach($p->targets as $t)
                                <i class="point point-{{ $t->platform }}" title="{{ Reseaux::nom($t->platform) }} — {{ \App\Models\PostTarget::STATUTS[$t->status] }}"></i>
                            @endforeach
                        </span>
                        @if($p->status === 'brouillon') <span style="color:var(--encre-3)">· brouillon</span> @endif
                        <span class="t">
                            @if(! $client) <strong>{{ $p->client->name }}</strong> · @endif
                            {{ $p->title ?: \Illuminate\Support\Str::limit($p->caption, 60) ?: 'Sans texte' }}
                        </span>
                    </a>
                @endforeach
            </div>
        @endforeach
    </div>
</div>

@if($brouillons->isNotEmpty())
    <div class="panneau">
        <h2>Brouillons sans date <span class="aide">à programmer</span></h2>
        <div class="tableau-cadre">
            <table>
                <tbody>
                @foreach($brouillons as $p)
                    <tr onclick="location='{{ $admin ? route('posts.edit', $p) : route('posts.show', $p) }}'" style="cursor:pointer">
                        <td>
                            @if(! $client) <strong>{{ $p->client->name }}</strong> · @endif
                            {{ $p->title ?: \Illuminate\Support\Str::limit($p->caption, 80) ?: 'Sans texte' }}
                        </td>
                        <td>
                            @foreach($p->targets as $t)
                                <i class="point point-{{ $t->platform }}"></i>
                            @endforeach
                        </td>
                        <td class="num" style="color:var(--encre-3)">{{ $p->updated_at->diffForHumans() }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    </div>
@endif
@endsection
