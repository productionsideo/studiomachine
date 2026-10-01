@extends('layouts.app')
@section('titre', $post->title ?: 'Publication')

@php
    use App\Services\Publication\Reseaux;
    $admin = auth()->user()->isAdmin();
@endphp

@section('contenu')
<div class="tete">
    <div>
        <h1>{{ $post->title ?: 'Publication' }}</h1>
        <p class="sous">
            {{ $post->client->name }}
            @if($post->campaign) · <a href="{{ route('campaigns.show', $post->campaign) }}"><u>{{ $post->campaign->name }}</u></a> @endif
            · {{ $post->scheduled_at ? ucfirst($post->heureLocale()->translatedFormat('l j F Y à H \h i')) : 'pas de date' }}
        </p>
    </div>
    <div class="outils">
        <span class="pastille pastille-{{ $post->status }}">{{ \App\Models\Post::STATUTS[$post->status] }}</span>
        @if($admin)
            @if($post->estModifiable())
                <a class="bouton-fin" href="{{ route('posts.edit', $post) }}">Modifier</a>
            @endif
            <form method="post" action="{{ route('posts.dupliquer', $post) }}">@csrf
                <button class="bouton-fin" type="submit">Dupliquer</button>
            </form>
            <form method="post" action="{{ route('posts.destroy', $post) }}" onsubmit="return confirm('Supprimer cette publication ?')">
                @csrf @method('delete')
                <button class="bouton-fin" type="submit">Supprimer</button>
            </form>
        @endif
    </div>
</div>

<div class="grille grille-2">
    <div class="panneau">
        <h2>Réseaux</h2>
        <div class="tableau-cadre">
            <table>
                <thead><tr><th>Réseau</th><th>Format</th><th>État</th><th>Détail</th><th></th></tr></thead>
                <tbody>
                @forelse($post->targets as $t)
                    <tr>
                        <td><span class="reseau"><i class="point point-{{ $t->platform }}"></i> {{ Reseaux::nom($t->platform) }}</span>
                            <div style="font-size:12px;color:var(--encre-3)">{{ $t->integration?->account_name }}</div></td>
                        <td>{{ Reseaux::CATALOGUE[$t->platform]['formats'][$t->format] ?? $t->format }}</td>
                        <td><span class="pastille pastille-{{ $t->status }}">{{ \App\Models\PostTarget::STATUTS[$t->status] }}</span></td>
                        <td style="font-size:13px">
                            @if($t->permalink)
                                <a href="{{ $t->permalink }}" target="_blank" rel="noopener"><u>Voir sur {{ Reseaux::nom($t->platform) }}</u></a>
                                <div style="color:var(--encre-3)">{{ $t->published_at?->setTimezone(config('publication.fuseau'))->format('Y-m-d H:i') }}</div>
                            @elseif($t->status === 'publiee')
                                Publiée {{ $t->published_at?->diffForHumans() }}
                            @endif
                            @if($t->last_error)
                                <div class="erreur" style="margin-top:0">{{ $t->last_error }}</div>
                            @endif
                            @if($t->status === 'en_cours')
                                <span style="color:var(--encre-3)">Le réseau traite le fichier — vérifié chaque minute.</span>
                            @endif
                        </td>
                        <td class="num">
                            @if($admin && $t->status === 'echec')
                                <form method="post" action="{{ route('posts.relancer', [$post, $t]) }}"
                                      onsubmit="return confirm('Avant de relancer : vérifiez sur {{ Reseaux::nom($t->platform) }} que la publication n’est pas sortie malgré l’erreur. Relancer ?')">
                                    @csrf <button class="bouton-fin" type="submit">Relancer</button>
                                </form>
                            @elseif($admin && $t->status === 'en_attente' && $post->status !== 'brouillon')
                                <form method="post" action="{{ route('posts.annuler', [$post, $t]) }}" onsubmit="return confirm('Ne pas publier sur ce réseau ?')">
                                    @csrf <button class="bouton-fin" type="submit">Retirer</button>
                                </form>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="vide">Aucun réseau choisi.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="panneau">
        <h2>Contenu</h2>
        <div class="panneau-corps">
            @if($post->media->isNotEmpty())
                <div class="choix-medias" style="margin-bottom:14px">
                    @foreach($post->media as $m)
                        <a class="vignette" href="{{ $m->url() }}" target="_blank" rel="noopener" title="{{ $m->original_name }}">
                            @if($m->vignetteUrl()) <img src="{{ $m->vignetteUrl() }}" alt=""> @else <div class="sans">vidéo</div> @endif
                            @if($m->isVideo() && $m->duration_seconds) <span class="badge">{{ gmdate('i:s', (int) $m->duration_seconds) }}</span> @endif
                        </a>
                    @endforeach
                </div>
            @endif
            <div style="white-space:pre-wrap;font-size:14px">{{ $post->caption ?: '—' }}</div>
            @if($post->link_url)
                <div class="aide-champ" style="margin-top:10px">Lien : {{ $post->link_url }}</div>
            @endif
            <div class="aide-champ" style="margin-top:10px">Créée par {{ $post->auteur?->name ?? '—' }}, {{ $post->created_at->diffForHumans() }}.</div>
        </div>
    </div>
</div>
@endsection
