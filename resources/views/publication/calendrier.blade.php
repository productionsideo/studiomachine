@extends('layouts.app')
@section('titre', 'Calendrier')

@php
    use App\Models\Post;
    use App\Models\PostTarget;
    use App\Services\Publication\Reseaux;
    use Illuminate\Support\Str;

    $base  = $client ? ['client' => $client->slug] : [];
    $admin = auth()->user()->isAdmin();

    if ($vue === 'semaine') {
        $titre = $debut->translatedFormat('j M') . ' – ' . $debut->copy()->addDays(6)->translatedFormat('j M Y');
        $prec  = $base + ['semaine' => $debut->copy()->subWeek()->toDateString()];
        $suiv  = $base + ['semaine' => $debut->copy()->addWeek()->toDateString()];
    } else {
        $titre = ucfirst($mois->translatedFormat('F Y'));
        $prec  = $base + ['vue' => 'mois', 'mois' => $mois->copy()->subMonth()->format('Y-m')];
        $suiv  = $base + ['vue' => 'mois', 'mois' => $mois->copy()->addMonth()->format('Y-m')];
    }

    // Ce qui peut glisser : l'équipe seulement, et tant que rien n'est parti.
    $deplacable = fn (Post $p) => $admin && $p->estModifiable();
@endphp

@section('contenu')
<div class="tete">
    <div>
        <h1>{{ $titre }}</h1>
        <p class="sous">{{ $client?->name ?? 'Tous les clients' }} — heure de Montréal.
            @if($admin) Glissez une publication pour changer son jour ou son heure. @endif
        </p>
    </div>
    <div class="outils">
        @if($admin)
            <form method="get" action="{{ route('calendrier.index') }}">
                @if($vue === 'mois') <input type="hidden" name="vue" value="mois"><input type="hidden" name="mois" value="{{ $mois->format('Y-m') }}">
                @else <input type="hidden" name="semaine" value="{{ $debut->toDateString() }}"> @endif
                <select name="client" onchange="this.form.submit()" style="width:auto">
                    <option value="">Tous les clients</option>
                    @foreach($clients as $c)
                        <option value="{{ $c->slug }}" @selected($client?->id === $c->id)>{{ $c->name }}</option>
                    @endforeach
                </select>
            </form>
        @endif
        <span class="bascule">
            <a @class(['bouton-fin', 'on' => $vue === 'semaine']) href="{{ route('calendrier.index', $base + ['semaine' => $debut->toDateString()]) }}">Semaine</a>
            <a @class(['bouton-fin', 'on' => $vue === 'mois']) href="{{ route('calendrier.index', $base + ['vue' => 'mois', 'mois' => $mois->format('Y-m')]) }}">Mois</a>
        </span>
        <a class="bouton-fin" href="{{ route('calendrier.index', $prec) }}">←</a>
        <a class="bouton-fin" href="{{ route('calendrier.index', $base + ($vue === 'mois' ? ['vue' => 'mois'] : [])) }}">{{ $vue === 'mois' ? 'Ce mois-ci' : 'Cette semaine' }}</a>
        <a class="bouton-fin" href="{{ route('calendrier.index', $suiv) }}">→</a>
        @if($admin)
            <a class="bouton bouton-accent" href="{{ route('posts.create', $base) }}">Nouvelle publication</a>
        @endif
    </div>
</div>

@if($vue === 'semaine')
    {{-- ================= Semaine, heure par heure ================= --}}
    <div class="panneau sem" style="--heure: {{ $heurePx }}px">
        <div class="sem-tete">
            <div></div>
            @foreach($jours as $jour)
                <div @class(['sem-jour-tete', 'auj' => $jour->toDateString() === $aujourdhui, 'fin' => $jour->isWeekend()])>
                    {{ ucfirst($jour->translatedFormat('l j')) }}
                </div>
            @endforeach
        </div>

        <div class="sem-corps" id="sem-corps">
            <div class="sem-heures">
                @for($h = 0; $h < 24; $h++)
                    <div class="sem-heure">{{ sprintf('%02d:00', $h) }}</div>
                @endfor
            </div>

            @foreach($jours as $jour)
                @php $cle = $jour->toDateString(); @endphp
                <div @class(['sem-col', 'auj' => $cle === $aujourdhui, 'passe' => $cle < $aujourdhui, 'fin' => $jour->isWeekend()])
                     data-jour="{{ $cle }}" @if($admin) data-depot @endif>
                    @if($cle === $aujourdhui)
                        <div class="sem-maintenant" id="sem-maintenant"></div>
                    @endif

                    @foreach($posts[$cle] ?? [] as $p)
                        @php
                            $place  = $placements[$cle][$p->id];
                            $media  = $p->media->first();
                            $vign   = $media?->vignetteUrl();
                            $texte  = $p->title ?: Str::limit($p->caption, 110) ?: 'Sans texte';
                            $lien   = $admin && $p->estModifiable() ? route('posts.edit', $p) : route('posts.show', $p);
                        @endphp
                        <a href="{{ $lien }}"
                           @class(['sem-carte', 'echec' => in_array($p->status, ['echec', 'partielle']), 'publiee' => $p->status === 'publiee', 'brouillon' => $p->status === 'brouillon', 'mobile' => $deplacable($p)])
                           style="top: {{ $place['haut'] / 60 * $heurePx }}px; left: calc({{ $place['colonne'] }} * 100% / {{ $place['colonnes'] }}); width: calc(100% / {{ $place['colonnes'] }} - 4px); --couleur: {{ $p->campaign?->color ?? $p->client->accent_color }}"
                           data-post="{{ $p->id }}" data-heure="{{ $p->heureLocale()->format('H:i') }}"
                           @if($deplacable($p)) draggable="true" data-url="{{ route('posts.deplacer', $p) }}" @endif
                           title="{{ Post::STATUTS[$p->status] }}{{ $deplacable($p) ? ' — glisser pour déplacer' : '' }}">
                            <span class="sem-carte-tete">
                                <span class="badges">
                                    @foreach($p->targets as $t)
                                        @include('publication._reseau', ['reseau' => $t->platform, 'titre' => Reseaux::nom($t->platform) . ' — ' . PostTarget::STATUTS[$t->status]])
                                    @endforeach
                                </span>
                                <span class="h" data-heure-texte>{{ $p->heureLocale()->format('H:i') }}</span>
                            </span>
                            <span class="t">
                                @if(! $client) <strong>{{ $p->client->name }}</strong> · @endif
                                {{ $texte }}
                            </span>
                            <span class="sem-carte-pied">
                                @if($vign) <img src="{{ $vign }}" alt="" loading="lazy"> @endif
                                <span class="etat">{{ Post::STATUTS[$p->status] }}</span>
                            </span>
                        </a>
                    @endforeach
                </div>
            @endforeach
        </div>
    </div>
@else
    {{-- ================= Mois ================= --}}
    <div class="panneau" style="margin-bottom:18px">
        <div class="cal">
            @foreach(['Lun', 'Mar', 'Mer', 'Jeu', 'Ven', 'Sam', 'Dim'] as $j)
                <div class="cal-entete">{{ $j }}</div>
            @endforeach

            @foreach($jours as $jour)
                @php $cle = $jour->toDateString(); @endphp
                <div @class(['cal-jour', 'hors' => $jour->month !== $mois->month, 'auj' => $cle === $aujourdhui])
                     data-jour="{{ $cle }}" @if($admin) data-depot @endif>
                    <div class="cal-tete-jour">
                        <span class="cal-num">{{ $jour->day }}</span>
                        @if($admin && $cle >= $aujourdhui)
                            <a class="cal-ajout" title="Programmer ce jour-là"
                               href="{{ route('posts.create', $base + ['jour' => $cle]) }}">+</a>
                        @endif
                    </div>

                    @foreach($posts[$cle] ?? [] as $p)
                        <a href="{{ route('posts.show', $p) }}"
                           @class(['cal-item', 'echec' => in_array($p->status, ['echec', 'partielle']), 'publiee' => $p->status === 'publiee', 'mobile' => $deplacable($p)])
                           style="--couleur: {{ $p->campaign?->color ?? $p->client->accent_color }}"
                           data-post="{{ $p->id }}" data-heure="{{ $p->heureLocale()->format('H:i') }}"
                           @if($deplacable($p)) draggable="true" data-url="{{ route('posts.deplacer', $p) }}" @endif
                           title="{{ Post::STATUTS[$p->status] }}{{ $deplacable($p) ? ' — glisser vers un autre jour' : '' }}">
                            <span class="h" data-heure-texte>{{ $p->heureLocale()->format('H:i') }}</span>
                            <span class="pts">
                                @foreach($p->targets as $t)
                                    <i class="point point-{{ $t->platform }}" title="{{ Reseaux::nom($t->platform) }} — {{ PostTarget::STATUTS[$t->status] }}"></i>
                                @endforeach
                            </span>
                            @if($p->status === 'brouillon') <span style="color:var(--encre-3)">· brouillon</span> @endif
                            <span class="t">
                                @if(! $client) <strong>{{ $p->client->name }}</strong> · @endif
                                {{ $p->title ?: Str::limit($p->caption, 60) ?: 'Sans texte' }}
                            </span>
                        </a>
                    @endforeach
                </div>
            @endforeach
        </div>
    </div>
@endif

@if($brouillons->isNotEmpty())
    <div class="panneau" style="margin-top:18px">
        <h2>Brouillons sans date <span class="aide">à programmer</span></h2>
        <div class="tableau-cadre">
            <table>
                <tbody>
                @foreach($brouillons as $p)
                    <tr onclick="location='{{ $admin ? route('posts.edit', $p) : route('posts.show', $p) }}'" style="cursor:pointer">
                        <td>
                            @if(! $client) <strong>{{ $p->client->name }}</strong> · @endif
                            {{ $p->title ?: Str::limit($p->caption, 80) ?: 'Sans texte' }}
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

<div class="toast" id="toast" role="status" aria-live="polite"></div>

@if($admin)
<script>
(() => {
    const HEURE = {{ $heurePx }};
    const PAS   = 15;                       // on aimante au quart d'heure
    const JETON = @json(csrf_token());
    const AUJ   = @json($aujourdhui);
    const semaine = !!document.getElementById('sem-corps');

    const toast = (msg, erreur = false) => {
        const t = document.getElementById('toast');
        t.textContent = msg;
        t.className = 'toast visible' + (erreur ? ' erreur' : '');
        clearTimeout(t._f);
        t._f = setTimeout(() => t.className = 'toast', 4000);
    };

    const deux = n => String(n).padStart(2, '0');
    let enMain = null;          // la carte qu'on déplace
    let repere = null;          // le trait qui montre l'heure visée

    // L'heure visée dans une colonne de la semaine, d'après la position du pointeur.
    const heureVisee = (col, y) => {
        const haut = Math.max(0, y - col.getBoundingClientRect().top);
        let min = Math.round((haut / HEURE * 60) / PAS) * PAS;
        min = Math.min(min, 24 * 60 - PAS);
        return deux(Math.floor(min / 60)) + ':' + deux(min % 60);
    };

    document.querySelectorAll('[draggable="true"][data-post]').forEach(c => {
        c.addEventListener('dragstart', e => {
            enMain = c;
            c.classList.add('en-main');
            e.dataTransfer.effectAllowed = 'move';
            e.dataTransfer.setData('text/plain', c.dataset.post);
        });
        c.addEventListener('dragend', () => {
            c.classList.remove('en-main');
            enMain = null;
            repere?.remove(); repere = null;
            document.querySelectorAll('.survol').forEach(z => z.classList.remove('survol'));
        });
    });

    document.querySelectorAll('[data-depot]').forEach(zone => {
        zone.addEventListener('dragover', e => {
            if (!enMain) return;
            e.preventDefault();
            zone.classList.add('survol');
            if (semaine) {
                const h = heureVisee(zone, e.clientY);
                if (!repere) { repere = document.createElement('div'); repere.className = 'sem-repere'; }
                if (repere.parentNode !== zone) zone.appendChild(repere);
                const [hh, mm] = h.split(':').map(Number);
                repere.style.top = ((hh * 60 + mm) / 60 * HEURE) + 'px';
                repere.dataset.heure = h;
            }
        });
        zone.addEventListener('dragleave', e => {
            if (!zone.contains(e.relatedTarget)) zone.classList.remove('survol');
        });
        zone.addEventListener('drop', async e => {
            e.preventDefault();
            zone.classList.remove('survol');
            const carte = enMain;
            if (!carte) return;

            const jour  = zone.dataset.jour;
            const heure = semaine ? heureVisee(zone, e.clientY) : carte.dataset.heure;   // au mois, on garde l'heure
            if (jour === carte.closest('[data-jour]').dataset.jour && heure === carte.dataset.heure) return;

            // On déplace tout de suite à l'écran ; on revient en arrière si le serveur refuse.
            const avant = { parent: carte.parentNode, top: carte.style.top, left: carte.style.left, width: carte.style.width, suivant: carte.nextSibling };
            const [hh, mm] = heure.split(':').map(Number);
            if (semaine) {
                carte.style.top = ((hh * 60 + mm) / 60 * HEURE) + 'px';
                carte.style.left = '0'; carte.style.width = 'calc(100% - 4px)';
                zone.appendChild(carte);
            } else {
                zone.appendChild(carte);
            }
            carte.classList.add('enregistre');

            try {
                const r = await fetch(carte.dataset.url, {
                    method: 'PATCH',
                    headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': JETON },
                    body: JSON.stringify({ quand: jour + ' ' + heure }),
                });
                const d = await r.json().catch(() => ({}));
                if (!r.ok) throw new Error(d.message || (d.errors ? Object.values(d.errors)[0][0] : 'Déplacement refusé.'));

                carte.dataset.heure = d.heure;
                carte.querySelector('[data-heure-texte]').textContent = d.heure;
                toast(d.message);
            } catch (err) {
                avant.parent.insertBefore(carte, avant.suivant);
                Object.assign(carte.style, { top: avant.top, left: avant.left, width: avant.width });
                toast(err.message, true);
            } finally {
                carte.classList.remove('enregistre');
            }
        });
    });
})();
</script>
@endif

@if($vue === 'semaine')
<script>
(() => {
    // Ouvre la semaine vers 7 h, et place le trait « maintenant ».
    const HEURE = {{ $heurePx }};
    const corps = document.getElementById('sem-corps');
    const trait = document.getElementById('sem-maintenant');
    const minutes = () => {
        const p = new Intl.DateTimeFormat('fr-CA', { timeZone: @json(config('publication.fuseau')), hour: '2-digit', minute: '2-digit', hourCycle: 'h23' }).formatToParts(new Date());
        return +p.find(x => x.type === 'hour').value * 60 + +p.find(x => x.type === 'minute').value;
    };
    const placer = () => { if (trait) trait.style.top = (minutes() / 60 * HEURE) + 'px'; };
    placer(); setInterval(placer, 60000);
    corps.scrollTop = Math.max(0, (trait ? minutes() / 60 - 2 : 7) * HEURE);
})();
</script>
@endif
@endsection
