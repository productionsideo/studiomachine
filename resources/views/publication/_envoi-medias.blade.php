{{--
    Envoi d'un fichier vers la médiathèque, partagé par la page Médias et
    l'éditeur de publication. Définit window.envoyerMedia(fichier, progression)
    qui résout avec le média créé ({id, nom, genre, vignette, duree, …}).

    - Vidéo et R2 configuré : envoi direct navigateur → R2, par parties. Le
      navigateur lit lui-même durée, dimensions et vignette (le serveur n'a
      plus besoin de ffprobe).
    - Sinon (images, ou R2 absent) : envoi au serveur en morceaux, comme avant.
--}}
<script>
window.envoyerMedia = (() => {
    const MORCEAU = {{ \App\Services\Mediatheque::tailleMorceau() }};
    const DIRECT = @json(\App\Services\R2::actif());
    const ROUTES = {
        morceau: @json(route('medias.morceau', $client)),
        debut:   @json(route('medias.direct.debut', $client)),
        fin:     @json(route('medias.direct.fin', $client)),
        abandon: @json(route('medias.direct.abandon', $client)),
    };
    const jeton = @json(csrf_token());
    const PARALLELES = 3;

    async function appeler(url, corps) {
        const r = await fetch(url, {
            method: 'POST', body: corps,
            headers: { 'X-CSRF-TOKEN': jeton, 'Accept': 'application/json' },
        });
        const json = await r.json().catch(() => ({}));
        if (!r.ok) throw new Error(json.erreur || json.message || `HTTP ${r.status}`);
        return json;
    }

    // --- Envoi classique, en morceaux, vers le serveur ------------------------
    async function parMorceaux(f, progression) {
        const id = Array.from(crypto.getRandomValues(new Uint8Array(16)), b => b.toString(16).padStart(2, '0')).join('');
        const total = Math.max(1, Math.ceil(f.size / MORCEAU));

        for (let i = 0; i < total; i++) {
            const d = new FormData();
            d.append('envoi', id); d.append('index', i); d.append('total', total); d.append('nom', f.name);
            d.append('morceau', f.slice(i * MORCEAU, (i + 1) * MORCEAU), f.name);
            const json = await appeler(ROUTES.morceau, d);
            progression((i + 1) / total);
            if (json.media) return json.media;
        }
        throw new Error('Envoi terminé sans média.');
    }

    // --- Lecture de la vidéo dans le navigateur --------------------------------
    // Durée, dimensions (rotation déjà appliquée par le navigateur) et une
    // vignette JPEG de 480 px. Si le navigateur ne sait pas lire le format
    // (HEVC sur Chrome, par ex.), on envoie sans : rien de bloquant.
    function analyser(f) {
        return new Promise(resolve => {
            const video = document.createElement('video');
            const url = URL.createObjectURL(f);
            const infos = {};
            let fini = false;
            const terminer = vignette => {
                if (fini) return;
                fini = true;
                URL.revokeObjectURL(url);
                resolve({ ...infos, vignette });
            };
            setTimeout(() => terminer(null), 20000);

            video.muted = true; video.playsInline = true; video.preload = 'auto';
            video.onerror = () => terminer(null);
            video.onloadedmetadata = () => {
                infos.largeur = video.videoWidth || null;
                infos.hauteur = video.videoHeight || null;
                infos.duree = isFinite(video.duration) ? video.duration : null;
                video.currentTime = Math.min(1, (infos.duree || 2) / 2);
            };
            video.onseeked = () => {
                try {
                    const l = 480, h = Math.round(480 * video.videoHeight / video.videoWidth / 2) * 2;
                    const c = document.createElement('canvas');
                    c.width = l; c.height = h;
                    c.getContext('2d').drawImage(video, 0, 0, l, h);
                    c.toBlob(b => terminer(b), 'image/jpeg', 0.8);
                } catch (e) { terminer(null); }
            };
            video.src = url;
        });
    }

    // --- Envoi direct vers R2 ---------------------------------------------------
    async function deposerPartie(url, morceau) {
        for (let essai = 1; ; essai++) {
            try {
                const r = await fetch(url, { method: 'PUT', body: morceau });
                if (!r.ok) throw new Error(`R2 a répondu ${r.status}`);
                const etag = r.headers.get('ETag');
                if (!etag) throw new Error('ETag illisible : la règle CORS du bucket doit exposer « ETag ».');
                return etag;
            } catch (e) {
                if (essai >= 3 || e.message.includes('CORS')) throw e;
                await new Promise(ok => setTimeout(ok, 1000 * essai));
            }
        }
    }

    async function direct(f, progression) {
        const analyse = analyser(f);

        const d = new FormData();
        d.append('nom', f.name); d.append('taille', f.size); d.append('type', f.type);
        const envoi = await appeler(ROUTES.debut, d);

        const parts = {};
        let faites = 0, suivante = 0;
        try {
            const ouvrier = async () => {
                while (suivante < envoi.urls.length) {
                    const i = suivante++;
                    parts[i + 1] = await deposerPartie(envoi.urls[i], f.slice(i * envoi.part, (i + 1) * envoi.part));
                    progression(++faites / envoi.urls.length * 0.98);
                }
            };
            await Promise.all(Array.from({ length: Math.min(PARALLELES, envoi.urls.length) }, ouvrier));
        } catch (e) {
            const a = new FormData(); a.append('envoi', envoi.envoi);
            appeler(ROUTES.abandon, a).catch(() => {});
            throw e;
        }

        const infos = await analyse;
        const fin = new FormData();
        fin.append('envoi', envoi.envoi);
        Object.entries(parts).forEach(([n, etag]) => fin.append(`parts[${n}]`, etag));
        ['largeur', 'hauteur', 'duree'].forEach(k => { if (infos[k]) fin.append(k, infos[k]); });
        if (infos.vignette) fin.append('vignette', infos.vignette, 'vignette.jpg');

        const json = await appeler(ROUTES.fin, fin);
        progression(1);
        return json.media;
    }

    return (f, progression = () => {}) =>
        DIRECT && f.type.startsWith('video/') ? direct(f, progression) : parMorceaux(f, progression);
})();
</script>
