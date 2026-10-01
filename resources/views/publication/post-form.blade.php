@extends('layouts.app')
@section('titre', $post->exists ? 'Modifier la publication' : 'Nouvelle publication')

@php
    use App\Services\Publication\Reseaux;
    $local = $post->heureLocale();
    $jour  = old('jour', $local?->toDateString());
    $heure = old('heure', $local?->format('H:i'));
    $choisisIds = old('media_ids', $choisis);
    $tt = $infosTiktok ?? [];
    $confTiktok = [
        'PUBLIC_TO_EVERYONE'    => 'Tout le monde',
        'MUTUAL_FOLLOW_FRIENDS' => 'Amis (abonnements mutuels)',
        'FOLLOWER_OF_CREATOR'   => 'Abonnés',
        'SELF_ONLY'             => 'Moi seulement',
    ];
@endphp

@section('contenu')
<div class="tete">
    <div>
        <h1>{{ $post->exists ? 'Modifier la publication' : 'Nouvelle publication' }}</h1>
        <p class="sous">{{ $client->name }}</p>
    </div>
    @if($post->exists)
        <div class="outils">
            <a class="bouton-fin" href="{{ route('posts.show', $post) }}">Voir l’état</a>
        </div>
    @endif
</div>

@if($errors->any())
    <div class="avis avis-garde">
        <strong>À corriger avant de programmer :</strong>
        <ul style="margin:6px 0 0 18px">
            @foreach($errors->messages() as $cle => $messages)
                @foreach($messages as $m)
                    <li>
                        @if(str_starts_with($cle, 'reseaux.') && isset(Reseaux::CATALOGUE[substr($cle, 8)]))
                            <strong>{{ Reseaux::nom(substr($cle, 8)) }}</strong> —
                        @endif
                        {{ $m }}
                    </li>
                @endforeach
            @endforeach
        </ul>
    </div>
@endif

<form method="post" id="editeur"
      action="{{ $post->exists ? route('posts.update', $post) : route('posts.store') }}">
    @csrf
    @if($post->exists) @method('patch') @endif
    <input type="hidden" name="client_id" value="{{ $client->id }}">

    <div class="editeur">
        <div class="grille">

            {{-- 1. Contenu ------------------------------------------------ --}}
            <div class="panneau">
                <h2>Contenu</h2>
                <div class="panneau-corps">
                    <div class="rangee">
                        <div class="champ">
                            <label for="campaign_id">Campagne</label>
                            <select id="campaign_id" name="campaign_id">
                                <option value="">— Aucune —</option>
                                @foreach($campagnes as $c)
                                    <option value="{{ $c->id }}" @selected(old('campaign_id', $post->campaign_id) == $c->id)>{{ $c->name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="champ">
                            <label for="title">Libellé interne</label>
                            <input type="text" id="title" name="title" maxlength="255"
                                   value="{{ old('title', $post->title) }}" placeholder="Jamais publié — sert au calendrier">
                        </div>
                    </div>

                    <div class="champ">
                        <label for="caption">Texte</label>
                        <textarea id="caption" name="caption" rows="7" data-compte>{{ old('caption', $post->caption) }}</textarea>
                        <div class="compteur" id="compteur-texte"></div>
                    </div>

                    <div class="champ" style="margin-bottom:0">
                        <label for="link_url">Lien (facultatif)</label>
                        <input type="url" id="link_url" name="link_url" maxlength="500"
                               value="{{ old('link_url', $post->link_url) }}" placeholder="https://…">
                        <div class="aide-champ">
                            Marqué automatiquement (utm_source = réseau, utm_campaign = campagne) pour que les demandes
                            reçues soient rattachées à cette publication. Ajouté au texte sur Facebook et YouTube ;
                            Instagram et TikTok ne rendent pas les liens cliquables.
                        </div>
                    </div>
                </div>
            </div>

            {{-- 2. Médias ------------------------------------------------- --}}
            <div class="panneau">
                <h2>Médias <span class="aide">cliquez pour choisir ; l’ordre compte pour un carrousel</span></h2>
                <div class="panneau-corps">
                    <div id="champs-medias">
                        @foreach($choisisIds as $id)
                            <input type="hidden" name="media_ids[]" value="{{ $id }}">
                        @endforeach
                    </div>

                    <div class="choix-medias" id="choix-medias">
                        @foreach($medias as $m)
                            <div class="vignette" data-id="{{ $m->id }}" data-video="{{ $m->isVideo() ? 1 : 0 }}"
                                 data-src="{{ $m->vignetteUrl() }}" data-vertical="{{ $m->estVertical() ? 1 : 0 }}"
                                 title="{{ $m->original_name }}">
                                @if($m->vignetteUrl())
                                    <img src="{{ $m->vignetteUrl() }}" alt="" loading="lazy">
                                @else
                                    <div class="sans">vidéo</div>
                                @endif
                                @if($m->isVideo() && $m->duration_seconds)
                                    <span class="badge">{{ gmdate($m->duration_seconds >= 3600 ? 'H:i:s' : 'i:s', (int) $m->duration_seconds) }}</span>
                                @endif
                                <span class="ordre"></span>
                            </div>
                        @endforeach
                    </div>

                    <div class="depot" id="depot" style="margin-top:12px">
                        Glissez des images ou vidéos ici, ou <u>parcourez</u>
                        <input type="file" id="fichier" multiple accept="image/jpeg,image/png,image/webp,video/mp4,video/quicktime" hidden>
                        <div id="envois"></div>
                    </div>
                </div>
            </div>

            {{-- 3. Réseaux ------------------------------------------------ --}}
            <div class="panneau">
                <h2>Réseaux
                    <a class="aide" href="{{ route('integrations.index', ['client' => $client->slug]) }}">gérer les comptes →</a>
                </h2>
                <div class="panneau-corps">
                    @error('reseaux') <div class="erreur" style="margin:0 0 10px">{{ $message }}</div> @enderror

                    @foreach(Reseaux::CATALOGUE as $cle => $r)
                        @php
                            $compte = $comptes[$cle] ?? null;
                            $cible  = $cibles[$cle] ?? null;
                            $actif  = (bool) old("reseaux.{$cle}.actif", $cible !== null);
                            $opt    = old("reseaux.{$cle}.options", $cible?->options ?? []);
                        @endphp

                        @if(! $compte)
                            <div class="reseau-bloc inactif">
                                <div class="reseau-tete">
                                    <i class="point point-{{ $cle }}"></i> {{ $r['nom'] }}
                                    <span class="compte">non connecté</span>
                                </div>
                            </div>
                            @continue
                        @endif

                        <div @class(['reseau-bloc', 'actif' => $actif]) data-reseau="{{ $cle }}" data-max="{{ $r['texte_max'] }}">
                            <label class="reseau-tete">
                                <input type="hidden" name="reseaux[{{ $cle }}][actif]" value="0">
                                <input type="checkbox" name="reseaux[{{ $cle }}][actif]" value="1" @checked($actif) data-bascule>
                                <i class="point point-{{ $cle }}"></i> {{ $r['nom'] }}
                                <span class="compte">{{ $cle === 'tiktok' && ! empty($tt['pseudo']) ? '@' . ltrim($tt['pseudo'], '@') : $compte->account_name }}</span>
                            </label>

                            <div class="reseau-corps">
                                <div class="rangee">
                                    <div class="champ">
                                        <label>Format</label>
                                        <select name="reseaux[{{ $cle }}][format]" data-format>
                                            @foreach($r['formats'] as $f => $nomFormat)
                                                <option value="{{ $f }}" @selected(old("reseaux.{$cle}.format", $cible?->format) === $f)>{{ $nomFormat }}</option>
                                            @endforeach
                                        </select>
                                    </div>
                                    @if($cle === 'youtube')
                                        <div class="champ">
                                            <label>Visibilité</label>
                                            <select name="reseaux[youtube][options][confidentialite]">
                                                @foreach(['public' => 'Publique', 'unlisted' => 'Non répertoriée', 'private' => 'Privée'] as $v => $l)
                                                    <option value="{{ $v }}" @selected(($opt['confidentialite'] ?? 'public') === $v)>{{ $l }}</option>
                                                @endforeach
                                            </select>
                                        </div>
                                    @endif
                                </div>

                                @if($cle === 'youtube')
                                    <div class="champ">
                                        <label>Titre de la vidéo</label>
                                        <input type="text" name="reseaux[youtube][options][titre]" maxlength="100"
                                               value="{{ $opt['titre'] ?? '' }}" data-compte data-max="100">
                                        <div class="compteur"></div>
                                    </div>
                                @endif

                                @if($cle === 'tiktok')
                                    @if(! empty($tt['erreur']))
                                        <div class="avis avis-garde">TikTok n’a pas répondu ({{ $tt['erreur'] }}). Les options du compte s’afficheront quand il répondra.</div>
                                    @endif
                                    <div class="champ">
                                        <label>Qui peut voir cette vidéo ?</label>
                                        {{-- TikTok interdit de choisir à la place de la personne : aucune valeur par défaut. --}}
                                        <select name="reseaux[tiktok][options][confidentialite]">
                                            <option value="">— Choisir —</option>
                                            @foreach($tt['confidentialites'] ?? array_keys($confTiktok) as $v)
                                                <option value="{{ $v }}" @selected(($opt['confidentialite'] ?? null) === $v)>{{ $confTiktok[$v] ?? $v }}</option>
                                            @endforeach
                                        </select>
                                    </div>
                                    <div class="coches">
                                        @foreach(['commentaires' => ['Autoriser les commentaires', 'commentaires_bloques'], 'duo' => ['Autoriser les duos', 'duo_bloque'], 'collage' => ['Autoriser les collages (stitch)', 'collage_bloque']] as $o => [$libelle, $bloque])
                                            <label @class(['grise' => ! empty($tt[$bloque])])>
                                                <input type="checkbox" name="reseaux[tiktok][options][{{ $o }}]" value="1"
                                                       @checked(! empty($opt[$o]) && empty($tt[$bloque])) @disabled(! empty($tt[$bloque]))>
                                                {{ $libelle }} @if(! empty($tt[$bloque])) <small>(désactivé sur le compte)</small> @endif
                                            </label>
                                        @endforeach
                                        <label><input type="checkbox" name="reseaux[tiktok][options][ia]" value="1" @checked(! empty($opt['ia']))> Contenu généré par IA</label>
                                        <label><input type="checkbox" name="reseaux[tiktok][options][contenu_commercial]" value="1" data-commercial @checked(! empty($opt['contenu_commercial']))> Contenu commercial (divulgation)</label>
                                        <div data-commercial-choix style="padding-left:24px;{{ empty($opt['contenu_commercial']) ? 'display:none' : '' }}">
                                            <label><input type="checkbox" name="reseaux[tiktok][options][votre_marque]" value="1" @checked(! empty($opt['votre_marque']))> Votre marque — promotion de sa propre entreprise</label>
                                            <label><input type="checkbox" name="reseaux[tiktok][options][contenu_marque]" value="1" data-marque @checked(! empty($opt['contenu_marque']))> Contenu de marque — partenariat rémunéré avec un tiers</label>
                                        </div>
                                    </div>
                                    <label class="coches" style="margin-top:0">
                                        <span style="display:flex;gap:8px;font-weight:400">
                                            <input type="checkbox" name="reseaux[tiktok][options][consentement]" value="1" @checked(! empty($opt['consentement']))>
                                            <span>En publiant, j’accepte la
                                                <a href="https://www.tiktok.com/legal/page/global/music-usage-confirmation/en" target="_blank" rel="noopener"><u>Music Usage Confirmation</u></a> de TikTok<span data-politique-marque style="{{ empty($opt['contenu_marque']) ? 'display:none' : '' }}"> et sa
                                                <a href="https://www.tiktok.com/legal/page/global/bc-policy/en" target="_blank" rel="noopener"><u>Branded Content Policy</u></a></span>.</span>
                                        </span>
                                    </label>
                                @endif

                                <div class="champ" style="margin-bottom:0">
                                    <label>Texte propre à {{ $r['nom'] }} <span style="font-weight:400;color:var(--encre-3)">(vide = texte commun)</span></label>
                                    <textarea name="reseaux[{{ $cle }}][texte]" rows="3" data-compte data-max="{{ $r['texte_max'] }}"
                                              placeholder="Laisser vide pour reprendre le texte commun">{{ old("reseaux.{$cle}.texte", $cible?->caption_override) }}</textarea>
                                    <div class="compteur"></div>
                                </div>
                            </div>
                        </div>
                    @endforeach

                    @if($comptes->isEmpty())
                        <div class="avis avis-info" style="margin:0">
                            Aucun réseau n’est connecté pour {{ $client->name }}.
                            <a href="{{ route('integrations.index', ['client' => $client->slug]) }}"><u>Connecter un compte</u></a>.
                        </div>
                    @endif
                </div>
            </div>

            {{-- 4. Quand -------------------------------------------------- --}}
            <div class="panneau">
                <h2>Quand <span class="aide">heure de Montréal</span></h2>
                <div class="panneau-corps">
                    <div class="rangee">
                        <div class="champ" style="margin-bottom:0">
                            <label for="jour">Jour</label>
                            <input type="date" id="jour" name="jour" value="{{ $jour }}">
                            @error('jour') <div class="erreur">{{ $message }}</div> @enderror
                        </div>
                        <div class="champ" style="margin-bottom:0">
                            <label for="heure">Heure</label>
                            <input type="time" id="heure" name="heure" value="{{ $heure }}" step="300">
                            @error('heure') <div class="erreur">{{ $message }}</div> @enderror
                        </div>
                    </div>
                </div>
                <div class="barre-actions">
                    <button type="submit" name="action" value="brouillon" class="bouton-fin">Enregistrer le brouillon</button>
                    <span class="espace"></span>
                    <button type="submit" name="action" value="maintenant" class="bouton-fin"
                            onclick="return confirm('Publier tout de suite sur les réseaux cochés ?')">Publier maintenant</button>
                    <button type="submit" name="action" value="programmer" class="bouton bouton-accent">Programmer</button>
                </div>
            </div>
        </div>

        {{-- Aperçu ------------------------------------------------------- --}}
        <aside class="editeur-apercu">
            <div class="onglets" id="onglets-apercu"></div>
            <div class="apercu">
                <div class="apercu-tete"><span class="rail-logo" style="width:26px;height:26px;font-size:10px">{{ mb_strtoupper(mb_substr($client->name, 0, 2)) }}</span> {{ $client->name }}</div>
                <div class="apercu-media" id="apercu-media">Aucun média</div>
                <div class="apercu-texte" id="apercu-texte"></div>
            </div>
            <p class="aide-champ">Aperçu indicatif : chaque réseau recadre et tronque à sa façon.</p>
        </aside>
    </div>
</form>

<script>
(() => {
    const form     = document.getElementById('editeur');
    const caption  = document.getElementById('caption');
    const champs   = document.getElementById('champs-medias');
    const grille   = document.getElementById('choix-medias');
    const onglets  = document.getElementById('onglets-apercu');
    let apercuReseau = null;

    // --- Compteurs de caractères -------------------------------------------
    const compter = (el) => {
        const sortie = el.parentElement.querySelector('.compteur');
        if (!sortie) return;
        const max = parseInt(el.dataset.max || 0, 10);
        const n = [...el.value].length;
        sortie.textContent = max ? `${n} / ${max}` : `${n} caractères`;
        sortie.classList.toggle('trop', max > 0 && n > max);
    };
    form.querySelectorAll('[data-compte]').forEach(el => { el.addEventListener('input', () => { compter(el); apercu(); }); compter(el); });

    // Le texte commun est compté contre la limite la plus stricte des réseaux cochés.
    const limiteCommune = () => {
        const actifs = [...form.querySelectorAll('.reseau-bloc.actif')].map(b => parseInt(b.dataset.max, 10));
        caption.dataset.max = actifs.length ? Math.min(...actifs) : '';
        compter(caption);
    };

    // --- Réseaux -------------------------------------------------------------
    form.querySelectorAll('[data-bascule]').forEach(c => c.addEventListener('change', () => {
        c.closest('.reseau-bloc').classList.toggle('actif', c.checked);
        limiteCommune(); dessinerOnglets();
    }));
    const commercial = form.querySelector('[data-commercial]');
    commercial?.addEventListener('change', () => {
        form.querySelector('[data-commercial-choix]').style.display = commercial.checked ? '' : 'none';
    });
    form.querySelector('[data-marque]')?.addEventListener('change', e => {
        form.querySelector('[data-politique-marque]').style.display = e.target.checked ? '' : 'none';
    });

    // --- Choix des médias ------------------------------------------------------
    const choisis = () => [...champs.querySelectorAll('input')].map(i => i.value);
    const numeroter = () => {
        const ids = choisis();
        grille.querySelectorAll('.vignette').forEach(v => {
            const i = ids.indexOf(v.dataset.id);
            v.classList.toggle('choisie', i >= 0);
            v.querySelector('.ordre').textContent = i >= 0 ? i + 1 : '';
        });
        apercu();
    };
    const basculer = (v) => {
        const existant = champs.querySelector(`input[value="${v.dataset.id}"]`);
        if (existant) existant.remove();
        else champs.insertAdjacentHTML('beforeend', `<input type="hidden" name="media_ids[]" value="${v.dataset.id}">`);
        numeroter();
    };
    grille.addEventListener('click', e => { const v = e.target.closest('.vignette'); if (v) basculer(v); });

    // --- Envoi des fichiers, en morceaux ---------------------------------------
    // Le serveur plafonne la taille des requêtes : on découpe le fichier.
    const MORCEAU = {{ \App\Services\Mediatheque::tailleMorceau() }};
    const depot = document.getElementById('depot');
    const fichier = document.getElementById('fichier');
    const envois = document.getElementById('envois');
    const jeton = form.querySelector('input[name=_token]').value;

    depot.addEventListener('click', e => { if (e.target === depot || e.target.tagName === 'U') fichier.click(); });
    depot.addEventListener('dragover', e => { e.preventDefault(); depot.classList.add('survol'); });
    depot.addEventListener('dragleave', () => depot.classList.remove('survol'));
    depot.addEventListener('drop', e => { e.preventDefault(); depot.classList.remove('survol'); envoyer([...e.dataTransfer.files]); });
    fichier.addEventListener('change', () => { envoyer([...fichier.files]); fichier.value = ''; });

    async function envoyer(fichiers) {
        for (const f of fichiers) await envoyerUn(f);
    }

    async function envoyerUn(f) {
        const ligne = document.createElement('div');
        ligne.className = 'envoi-ligne';
        ligne.innerHTML = `<span></span><div class="jauge-rail"><div class="jauge-part" style="width:0"></div></div>`;
        ligne.querySelector('span').textContent = f.name;
        envois.appendChild(ligne);
        const barre = ligne.querySelector('.jauge-part');

        const id = Array.from(crypto.getRandomValues(new Uint8Array(16)), b => b.toString(16).padStart(2, '0')).join('');
        const total = Math.max(1, Math.ceil(f.size / MORCEAU));

        try {
            for (let i = 0; i < total; i++) {
                const donnees = new FormData();
                donnees.append('envoi', id);
                donnees.append('index', i);
                donnees.append('total', total);
                donnees.append('nom', f.name);
                donnees.append('morceau', f.slice(i * MORCEAU, (i + 1) * MORCEAU), f.name);

                const r = await fetch(@json(route('medias.morceau', $client)), {
                    method: 'POST', body: donnees,
                    headers: { 'X-CSRF-TOKEN': jeton, 'Accept': 'application/json' },
                });
                const json = await r.json().catch(() => ({}));
                if (!r.ok) throw new Error(json.erreur || json.message || `HTTP ${r.status}`);

                barre.style.width = `${Math.round((i + 1) / total * 100)}%`;
                if (json.media) ajouterVignette(json.media);
            }
            setTimeout(() => ligne.remove(), 1500);
        } catch (err) {
            ligne.querySelector('span').textContent = `${f.name} — ${err.message}`;
            ligne.style.color = 'var(--accent)';
        }
    }

    function ajouterVignette(m) {
        const v = document.createElement('div');
        v.className = 'vignette';
        v.dataset.id = m.id; v.dataset.video = m.genre === 'video' ? 1 : 0;
        v.dataset.src = m.vignette || ''; v.dataset.vertical = (m.largeur && m.hauteur && m.largeur / m.hauteur < 0.8) ? 1 : 0;
        v.title = m.nom;
        v.innerHTML = (m.vignette ? `<img src="${m.vignette}" alt="">` : `<div class="sans">vidéo</div>`) + `<span class="ordre"></span>`;
        grille.prepend(v);
        basculer(v);
    }

    // --- Aperçu ------------------------------------------------------------------
    function dessinerOnglets() {
        const actifs = [...form.querySelectorAll('.reseau-bloc.actif')].map(b => b.dataset.reseau);
        if (!actifs.includes(apercuReseau)) apercuReseau = actifs[0] || null;
        onglets.innerHTML = '';
        actifs.forEach(r => {
            const b = document.createElement('button');
            b.type = 'button';
            b.className = 'bouton-fin' + (r === apercuReseau ? ' on' : '');
            b.textContent = form.querySelector(`.reseau-bloc[data-reseau=${r}] .reseau-tete`).innerText.split('\n')[0].trim() || r;
            b.onclick = () => { apercuReseau = r; dessinerOnglets(); };
            onglets.appendChild(b);
        });
        apercu();
    }

    function apercu() {
        const bloc = apercuReseau && form.querySelector(`.reseau-bloc[data-reseau=${apercuReseau}]`);
        const propre = bloc?.querySelector('textarea')?.value.trim();
        document.getElementById('apercu-texte').textContent = propre || caption.value;

        const premier = choisis()[0];
        const v = premier && grille.querySelector(`.vignette[data-id="${premier}"]`);
        const media = document.getElementById('apercu-media');
        const format = bloc?.querySelector('[data-format]')?.value;
        media.classList.toggle('vertical', ['reel', 'short', 'story'].includes(format) || apercuReseau === 'tiktok');
        media.innerHTML = v ? (v.dataset.src ? `<img src="${v.dataset.src}" alt="">` : 'Vidéo') : 'Aucun média';
    }

    form.querySelectorAll('[data-format]').forEach(s => s.addEventListener('change', apercu));
    limiteCommune(); numeroter(); dessinerOnglets();
})();
</script>
@endsection
