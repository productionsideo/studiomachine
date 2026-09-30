@extends('layouts.app')
@section('titre', 'Assistant Claude')

@section('contenu')
<div class="tete">
    <div>
        <h1>Assistant Claude</h1>
        <p class="sous">
            Claude lit les commentaires, les classe, et rédige une réponse.
            Il ne décide pas de l'envoyer — cette règle-là est dans le code.
        </p>
    </div>
    @if($clients->isNotEmpty())
        <div class="outils">
            <select onchange="location = this.value">
                @foreach($clients as $c)
                    <option value="{{ route('assistant.index', ['client' => $c->slug]) }}"
                            @selected($client && $c->id === $client->id)>{{ $c->name }}</option>
                @endforeach
            </select>
        </div>
    @endif
</div>

@if(session('ok'))     <div class="avis avis-ok">{{ session('ok') }}</div> @endif
@if(session('erreur')) <div class="avis avis-garde">{{ session('erreur') }}</div> @endif

@if(! $actif)
    <div class="avis avis-garde">
        <strong>Aucune clé Anthropic n'est configurée — l'assistant est inactif.</strong><br>
        Ajoutez <code>ANTHROPIC_API_KEY=sk-ant-…</code> dans
        <code>/home/studiomachine/gestion-app/.env</code>, puis videz le cache de configuration.
        La clé se crée sur <a href="https://console.anthropic.com" target="_blank" rel="noopener">console.anthropic.com</a>.
        Sans elle, la boîte de réception fonctionne : simplement, aucune suggestion n'apparaît.
    </div>
@endif

@if(! $client)
    <div class="panneau"><div class="panneau-corps">
        <p>Aucun client. Créez-en un pour régler l'assistant.</p>
    </div></div>
@else

<div class="grille grille-2">

    {{-- ─── Réglages ─────────────────────────────────────────────── --}}
    <div class="panneau">
        <h2>Réglages — {{ $client->name }}</h2>
        <div class="panneau-corps">
            <form method="post" action="{{ route('assistant.update', $client) }}">
                @csrf @method('patch')

                <label style="display:flex;gap:10px;align-items:flex-start;margin-bottom:6px">
                    <input type="checkbox" name="ai_auto_reply" value="1" style="margin-top:3px"
                           @checked($client->ai_auto_reply)>
                    <span>
                        <strong>Laisser Claude répondre seul aux commentaires anodins</strong><br>
                        <span style="color:var(--encre-3);font-size:13px">
                            Un merci, un compliment, un emoji. Rien d'autre.
                        </span>
                    </span>
                </label>

                <div class="avis" style="margin:12px 0;font-size:13px">
                    Même coché, une réponse ne part <strong>jamais</strong> toute seule dès qu'un
                    de ces sujets est en jeu :
                    <strong>{{ implode(', ', $sensibles) }}</strong>.
                    Elle reste en brouillon, à valider ici.
                </div>

                <label for="ai_context">Ce que Claude sait de {{ $client->name }}</label>
                <textarea name="ai_context" id="ai_context" rows="12"
                          placeholder="Les faits que Claude a le droit d'avancer : projets en cours, services, coordonnées, ton de la marque…">{{ old('ai_context', $client->ai_context) }}</textarea>
                <p style="color:var(--encre-3);font-size:13px;margin:6px 0 16px">
                    Claude n'avance <strong>aucun fait qui ne figure pas ici</strong> — pas de prix,
                    pas de délai, pas de disponibilité. Ce qui manque, il invite à le demander.
                    C'est donc ce champ qui décide de ce qu'il peut dire.
                </p>

                <label for="ai_signature">Signature (facultatif)</label>
                <input type="text" name="ai_signature" id="ai_signature"
                       value="{{ old('ai_signature', $client->ai_signature) }}"
                       placeholder="— L'équipe de Construction CRD">

                <button type="submit" class="bouton" style="margin-top:16px">Enregistrer</button>
            </form>
        </div>
    </div>

    {{-- ─── Banc d'essai ─────────────────────────────────────────── --}}
    <div>
        <div class="panneau" style="margin-bottom:16px">
            <h2>
                Banc d'essai
                <span class="aide">rien n'est enregistré</span>
            </h2>
            <div class="panneau-corps">
                <p style="color:var(--encre-3);font-size:13px;margin-top:0">
                    Aucun réseau n'est encore connecté, donc aucun vrai commentaire n'arrive.
                    Vous pouvez malgré tout vérifier <em>aujourd'hui</em> que Claude classe juste
                    et écrit dans le bon ton. Essayez « superbe maison ! », puis « c'est combien ? » :
                    la deuxième doit rester en brouillon.
                </p>

                <form method="post" action="{{ route('assistant.essai') }}">
                    @csrf
                    <input type="hidden" name="client_id" value="{{ $client->id }}">

                    <label for="texte">Un commentaire, comme s'il venait d'arriver</label>
                    <textarea name="texte" id="texte" rows="3" required
                              placeholder="Wow, superbe maison ! Vous construisez à Beauport ?">{{ old('texte') }}</textarea>

                    <div style="display:flex;gap:10px;align-items:end;margin-top:10px">
                        <div style="min-width:140px">
                            <label for="plateforme">Réseau</label>
                            <select name="plateforme" id="plateforme">
                                @foreach(['facebook','instagram','tiktok','youtube'] as $r)
                                    <option value="{{ $r }}" @selected(old('plateforme') === $r)>{{ ucfirst($r) }}</option>
                                @endforeach
                            </select>
                        </div>
                        <button type="submit" class="bouton" @disabled(! $actif)>Soumettre à Claude</button>
                    </div>
                </form>
            </div>
        </div>

        @if($essai = session('essai'))
            <div class="panneau">
                <h2>
                    Ce que Claude a répondu
                    <span class="aide">{{ $modele }}</span>
                </h2>
                <div class="panneau-corps">

                    <div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:12px">
                        <span class="pastille pastille-{{ $essai['categorie'] === 'anodin' ? 'gagne' : 'nouveau' }}">
                            {{ $essai['categorie'] }}
                        </span>
                        <span class="pastille">certitude {{ $essai['certitude'] }}</span>
                        @foreach($essai['sujets'] as $sujet)
                            @if($sujet !== 'aucun')
                                <span class="pastille pastille-qualifie">{{ $sujet }}</span>
                            @endif
                        @endforeach
                    </div>

                    @if($essai['auto'])
                        <div class="avis avis-ok">
                            <strong>Cette réponse serait partie toute seule.</strong>
                            Commentaire anodin, aucun sujet engageant, et l'envoi automatique est activé.
                        </div>
                    @else
                        <div class="avis avis-garde">
                            <strong>Cette réponse resterait en brouillon</strong>, à valider par une personne.<br>
                            <span style="font-size:13px">{{ $essai['note'] }}</span>
                        </div>
                    @endif

                    @if(filled($essai['reponse']))
                        <label style="margin-top:14px">La réponse proposée</label>
                        <div style="background:var(--fond);border:1px solid var(--trait);border-radius:8px;padding:12px;line-height:1.55">
                            {{ $essai['reponse'] }}
                        </div>
                    @else
                        <p style="color:var(--encre-3);font-size:14px">
                            Claude ne propose aucune réponse — il a jugé qu'il n'y a rien à répondre.
                        </p>
                    @endif

                    @if($essai['auto'])
                        <p style="color:var(--encre-3);font-size:13px;margin-bottom:0">{{ $essai['note'] }}</p>
                    @endif
                </div>
            </div>
        @endif
    </div>
</div>
@endif
@endsection
