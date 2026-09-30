@extends('layouts.app')
@section('titre', 'Commentaires')

@section('contenu')
<div class="tete">
    <div>
        <h1>Commentaires</h1>
        <p class="sous">
            Les commentaires reçus sur vos publications, au même endroit.
            @if($assistant)
                Claude les lit et propose une réponse ; vous validez.
            @endif
        </p>
    </div>
    <div class="outils">
        @foreach(['a_repondre' => 'À traiter', 'tous' => 'Tous'] as $etat => $label)
            <a href="{{ request()->fullUrlWithQuery(['etat' => $etat]) }}"
               @class(['bouton-fin', 'on' => request('etat', 'a_repondre') === $etat])>{{ $label }}</a>
        @endforeach
    </div>
</div>

@if(session('ok'))     <div class="avis avis-ok">{{ session('ok') }}</div> @endif
@if(session('erreur')) <div class="avis avis-garde">{{ session('erreur') }}</div> @endif

@if(empty($branches))
    <div class="avis avis-garde">
        <strong>Aucun réseau n'est encore connecté.</strong><br>
        Cette boîte se remplira dès que les comptes TikTok, Facebook, Instagram et YouTube
        seront branchés. Facebook, Instagram et TikTok exigent une approbation de la plateforme
        avant de donner accès aux commentaires — c'est une démarche externe, indépendante du site.
        @if($assistant)
            En attendant, l'<a href="{{ route('assistant.index') }}">assistant</a> est déjà
            testable : on lui soumet un commentaire à la main.
        @endif
    </div>
@endif

@if(! $assistant)
    <div class="avis avis-info">
        L'assistant Claude est inactif — aucune clé Anthropic n'est configurée.
        Les commentaires s'affichent, mais sans suggestion de réponse.
        <a href="{{ route('assistant.index') }}">Voir comment l'activer →</a>
    </div>
@endif

@forelse($comments as $com)
    @php $sensibles = $com->sujetsSensibles(); @endphp

    <div class="panneau" style="margin-bottom:14px">
        <div class="panneau-corps">

            {{-- Le commentaire lui-même --}}
            <div style="display:flex;justify-content:space-between;gap:16px;flex-wrap:wrap">
                <div style="flex:1;min-width:260px">
                    <div style="font-size:13px;color:var(--encre-3);margin-bottom:6px">
                        <span class="reseau">
                            <i class="point point-{{ $com->platform }}"></i>{{ ucfirst($com->platform) }}
                        </span>
                        &nbsp;·&nbsp;
                        <strong style="color:var(--encre-2)">{{ $com->author_name ?: 'Anonyme' }}</strong>
                        &nbsp;·&nbsp;
                        {{ optional($com->posted_at)->translatedFormat('j M, H:i') }}
                        @if($com->capsulePost?->capsule)
                            &nbsp;·&nbsp; capsule <strong>#{{ $com->capsulePost->capsule->number }}</strong>
                        @endif
                    </div>
                    <p style="line-height:1.55;margin:0">{{ $com->text }}</p>
                </div>

                {{-- Le classement de Claude --}}
                @if($com->ai_analyzed_at)
                    <div style="display:flex;gap:6px;flex-wrap:wrap;align-items:flex-start">
                        <span class="pastille pastille-{{ $com->ai_category === 'anodin' ? 'gagne' : 'nouveau' }}">
                            {{ $com->ai_category }}
                        </span>
                        @foreach($sensibles as $sujet)
                            <span class="pastille pastille-qualifie">{{ $sujet }}</span>
                        @endforeach
                    </div>
                @endif
            </div>

            {{-- Ce qui a été fait, ou ce qu'il reste à faire --}}
            <div style="margin-top:14px;padding-top:14px;border-top:1px solid var(--trait)">

                @if($com->reply_status === 'envoyee')
                    <div style="font-size:13px;color:var(--encre-3);margin-bottom:6px">
                        Répondu par <strong>{{ $com->auteurReponse() }}</strong>,
                        {{ optional($com->replied_at)->translatedFormat('j M, H:i') }}
                        <span class="pastille pastille-gagne" style="margin-left:6px">Publié</span>
                    </div>
                    <p style="margin:0;line-height:1.55">{{ $com->reply_text }}</p>

                @elseif($com->reply_status === 'en_attente')
                    <div style="font-size:13px;color:var(--encre-3);margin-bottom:6px">
                        Validé par <strong>{{ $com->auteurReponse() }}</strong>
                        <span class="pastille pastille-qualifie" style="margin-left:6px">En file</span>
                        — partira dès que le compte {{ ucfirst($com->platform) }} sera connecté.
                    </div>
                    <p style="margin:0;line-height:1.55">{{ $com->reply_text }}</p>

                @elseif($com->reply_status === 'echec')
                    <div class="avis avis-garde" style="margin:0">
                        <strong>{{ ucfirst($com->platform) }} a refusé l'envoi.</strong>
                        {{ $com->reply_error }}<br>
                        <span style="font-size:13px">La réponse reste en file : rien n'est perdu.</span>
                    </div>

                @elseif($com->reply_status === 'ignoree')
                    <span class="pastille">Écarté</span>

                @else
                    {{-- À traiter --}}
                    @if($com->ai_error)
                        <div class="avis avis-garde" style="margin-bottom:10px;font-size:13px">
                            Claude n'a pas pu lire ce commentaire : {{ $com->ai_error }}
                        </div>
                    @elseif($com->ai_analyzed_at && $sensibles)
                        <div style="font-size:13px;color:var(--alerte);margin-bottom:8px">
                            <strong>Validation requise.</strong> {{ $com->ai_note }}
                        </div>
                    @elseif($com->ai_analyzed_at)
                        <div style="font-size:13px;color:var(--encre-3);margin-bottom:8px">
                            {{ $com->ai_note }}
                        </div>
                    @endif

                    <form method="post" action="{{ route('comments.reply', $com) }}">
                        @csrf
                        <label for="r{{ $com->id }}">
                            {{ $com->ai_draft ? 'Réponse proposée par Claude — modifiable' : 'Votre réponse' }}
                        </label>
                        <textarea name="reply_text" id="r{{ $com->id }}" rows="3" required
                                  placeholder="Répondre…">{{ $com->ai_draft }}</textarea>
                        <button type="submit" class="bouton" style="margin-top:10px">Valider et envoyer</button>
                    </form>

                    <div style="display:flex;gap:8px;margin-top:8px;flex-wrap:wrap">
                        @if($assistant)
                            <form method="post" action="{{ route('comments.suggest', $com) }}">
                                @csrf
                                <button type="submit" class="bouton-fin">
                                    {{ $com->ai_analyzed_at ? 'Redemander à Claude' : 'Demander à Claude' }}
                                </button>
                            </form>
                        @endif
                        <form method="post" action="{{ route('comments.ignore', $com) }}">
                            @csrf
                            <button type="submit" class="bouton-fin">Écarter</button>
                        </form>
                    </div>
                @endif
            </div>
        </div>
    </div>
@empty
    <div class="panneau">
        <div class="panneau-corps vide" style="padding:28px;text-align:center">
            @if(empty($branches))
                Aucun réseau connecté — rien à afficher pour l'instant.
            @else
                Aucun commentaire en attente.
            @endif
        </div>
    </div>
@endforelse

{{ $comments->links() }}
@endsection
