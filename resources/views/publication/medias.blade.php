@extends('layouts.app')
@section('titre', 'Médiathèque')

@php $admin = auth()->user()->isAdmin(); @endphp

@section('contenu')
<div class="tete">
    <div>
        <h1>Médiathèque</h1>
        <p class="sous">{{ $client->name }} — images et vidéos prêtes à publier.</p>
    </div>
</div>

@if($admin)
    <div class="panneau" style="margin-bottom:18px">
        <div class="panneau-corps">
            <div class="depot" id="depot">
                Glissez des images ou vidéos ici, ou <u>parcourez</u> — JPEG, PNG, WebP, MP4, MOV, jusqu’à 1 Go.
                <input type="file" id="fichier" multiple accept="image/jpeg,image/png,image/webp,video/mp4,video/quicktime" hidden>
                <div id="envois"></div>
            </div>
        </div>
    </div>
@endif

<div class="galerie">
    @forelse($medias as $m)
        <div class="galerie-carte">
            <a class="vignette" href="{{ $m->url() }}" target="_blank" rel="noopener">
                @if($m->vignetteUrl()) <img src="{{ $m->vignetteUrl() }}" alt="" loading="lazy"> @else <div class="sans">vidéo</div> @endif
                @if($m->isVideo() && $m->duration_seconds) <span class="badge">{{ gmdate('i:s', (int) $m->duration_seconds) }}</span> @endif
            </a>
            <div class="infos">
                <strong title="{{ $m->original_name }}">{{ $m->original_name }}</strong>
                {{ $m->width && $m->height ? "{$m->width}×{$m->height}" : '' }} · {{ $m->tailleLisible() }}
                @if($m->posts_count) · {{ $m->posts_count }} publication(s) @endif
            </div>
            @if($admin)
                <form method="post" action="{{ route('medias.destroy', $m) }}"
                      onsubmit="return confirm(@js($m->posts_count
                          ? "Ce média sert à {$m->posts_count} publication(s). Il en sera retiré, puis supprimé. Continuer ?"
                          : 'Supprimer ce média ?'))">
                    @csrf @method('delete')
                    <button class="lien-discret" type="submit" style="padding:0">Supprimer</button>
                </form>
            @endif
        </div>
    @empty
        <div class="vide" style="grid-column:1/-1">La médiathèque est vide.</div>
    @endforelse
</div>

{{ $medias->links() }}

@if($admin)
@include('publication._envoi-medias')
<script>
(() => {
    const depot = document.getElementById('depot');
    const fichier = document.getElementById('fichier');
    const envois = document.getElementById('envois');
    let recharger = false;

    depot.addEventListener('click', e => { if (e.target === depot || e.target.tagName === 'U') fichier.click(); });
    depot.addEventListener('dragover', e => { e.preventDefault(); depot.classList.add('survol'); });
    depot.addEventListener('dragleave', () => depot.classList.remove('survol'));
    depot.addEventListener('drop', e => { e.preventDefault(); depot.classList.remove('survol'); envoyer([...e.dataTransfer.files]); });
    fichier.addEventListener('change', () => { envoyer([...fichier.files]); fichier.value = ''; });

    async function envoyer(fichiers) {
        for (const f of fichiers) await envoyerUn(f);
        if (recharger) location.reload();
    }

    async function envoyerUn(f) {
        const ligne = document.createElement('div');
        ligne.className = 'envoi-ligne';
        ligne.innerHTML = `<span></span><div class="jauge-rail"><div class="jauge-part" style="width:0"></div></div>`;
        ligne.querySelector('span').textContent = f.name;
        envois.appendChild(ligne);
        const barre = ligne.querySelector('.jauge-part');

        try {
            await envoyerMedia(f, p => barre.style.width = `${Math.round(p * 100)}%`);
            recharger = true;
        } catch (err) {
            ligne.querySelector('span').textContent = `${f.name} — ${err.message}`;
            ligne.style.color = 'var(--accent)';
            recharger = false;
        }
    }
})();
</script>
@endif
@endsection
