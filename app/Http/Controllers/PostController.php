<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ResolvesClient;
use App\Models\Campaign;
use App\Models\Client;
use App\Models\Integration;
use App\Models\MediaAsset;
use App\Models\Post;
use App\Models\PostTarget;
use App\Services\Connexion\TiktokCreateur;
use App\Services\Publication\Reseaux;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Rédaction, programmation et suivi des publications.
 *
 * Rédiger et programmer est réservé à l'équipe (middleware admin sur les
 * routes d'écriture) ; un client voit ses publications et leur état.
 *
 * Une publication n'est programmée que si chaque réseau visé l'accepte
 * d'avance : les mêmes vérifications que celles faites au moment d'envoyer
 * tournent à l'enregistrement. Un Reel de 95 secondes est refusé maintenant,
 * dans l'éditeur, plutôt qu'à 9 h par Facebook, sans personne pour le voir.
 */
class PostController extends Controller
{
    use ResolvesClient;

    public function show(Request $request, Post $post)
    {
        $this->authorizeClient($request, $post->client_id);

        $post->load('targets.integration', 'media', 'campaign', 'client', 'auteur');

        return view('publication.post-show', ['post' => $post]);
    }

    public function create(Request $request, TiktokCreateur $tiktok)
    {
        $client = $this->resolveClient($request);

        if (! $client) {
            return view('publication.choisir-client', [
                'clients' => Client::where('active', true)->orderBy('name')->get(),
                'suite'   => 'posts.create',
            ]);
        }

        $post = new Post([
            'client_id'   => $client->id,
            'campaign_id' => $request->query('campagne'),
        ]);

        // Un jour cliqué dans le calendrier arrive pré-rempli, à 9 h.
        if ($jour = $request->query('jour')) {
            try {
                $post->scheduled_at = Carbon::parse("{$jour} 09:00", config('publication.fuseau'))->utc();
            } catch (\Throwable) {
                // date illisible : on laisse vide
            }
        }

        return $this->formulaire($post, $client, $tiktok);
    }

    public function store(Request $request, TiktokCreateur $tiktok)
    {
        $client = Client::findOrFail($request->input('client_id'));
        $this->authorizeClient($request, $client->id);

        $post = new Post(['client_id' => $client->id, 'created_by' => $request->user()->id]);

        return $this->enregistrer($request, $post, $client);
    }

    public function edit(Request $request, Post $post, TiktokCreateur $tiktok)
    {
        $this->authorizeClient($request, $post->client_id);

        if (! $post->estModifiable()) {
            return redirect()->route('posts.show', $post)
                ->with('ok', 'Cette publication est déjà partie, en tout ou en partie : elle ne se modifie plus. Dupliquez-la pour en faire une nouvelle.');
        }

        return $this->formulaire($post->load('targets', 'media'), $post->client, $tiktok);
    }

    public function update(Request $request, Post $post)
    {
        $this->authorizeClient($request, $post->client_id);

        abort_unless($post->estModifiable(), 409, 'Publication déjà partie.');

        return $this->enregistrer($request, $post, $post->client);
    }

    public function destroy(Request $request, Post $post)
    {
        $this->authorizeClient($request, $post->client_id);

        // Effacer la ligne n'effacerait pas la publication sur le réseau : on
        // refuse plutôt que de laisser croire le contraire.
        if ($post->targets()->whereIn('status', ['publiee', 'en_cours'])->exists()) {
            return back()->with('ok', 'Cette publication est en ligne (ou en cours d’envoi) : supprimez-la d’abord sur le réseau. Elle reste ici pour l’historique.');
        }

        $post->delete();

        return redirect()->route('calendrier.index', ['client' => $post->client->slug])
            ->with('ok', 'Publication supprimée.');
    }

    public function dupliquer(Request $request, Post $post)
    {
        $this->authorizeClient($request, $post->client_id);

        $copie = DB::transaction(function () use ($post, $request) {
            $copie = $post->replicate(['status', 'scheduled_at']);
            $copie->fill(['status' => 'brouillon', 'scheduled_at' => null, 'created_by' => $request->user()->id]);
            $copie->title = trim(($post->title ?: 'Publication') . ' (copie)');
            $copie->save();

            foreach ($post->media as $m) {
                $copie->media()->attach($m->id, ['position' => $m->pivot->position]);
            }

            foreach ($post->targets as $t) {
                $copie->targets()->create($t->only(['integration_id', 'platform', 'format', 'caption_override', 'options']));
            }

            return $copie;
        });

        return redirect()->route('posts.edit', $copie)->with('ok', 'Copie créée en brouillon.');
    }

    /**
     * Remet une cible en échec dans la file. Volontairement manuel : si
     * l'échec est survenu pendant l'envoi, la publication est peut-être
     * sortie quand même — c'est à un humain de le vérifier.
     */
    public function relancer(Request $request, Post $post, PostTarget $cible)
    {
        $this->authorizeClient($request, $post->client_id);
        abort_unless($cible->post_id === $post->id && $cible->status === 'echec', 404);

        $cible->update([
            'status'          => 'en_attente',
            'attempts'        => 0,
            'next_attempt_at' => null,
            'started_at'      => null,
            'external_job_id' => null,
            'last_error'      => null,
        ]);

        if (! $post->scheduled_at) {
            $post->update(['scheduled_at' => now()]);
        }

        $post->recalculerStatut();

        return back()->with('ok', Reseaux::nom($cible->platform) . ' : nouvel essai dans la minute.');
    }

    public function annulerCible(Request $request, Post $post, PostTarget $cible)
    {
        $this->authorizeClient($request, $post->client_id);
        abort_unless($cible->post_id === $post->id && $cible->status === 'en_attente', 404);

        $cible->update(['status' => 'annulee']);
        $post->recalculerStatut();

        return back()->with('ok', Reseaux::nom($cible->platform) . ' retiré de cette publication.');
    }

    // ---------------------------------------------------------------------

    private function formulaire(Post $post, Client $client, TiktokCreateur $tiktok)
    {
        $comptes = $client->integrations()
            ->whereIn('platform', array_keys(Reseaux::CATALOGUE))
            ->where('active', true)
            ->get()
            ->keyBy('platform');

        // TikTok impose d'afficher le compte visé et de proposer SES niveaux
        // de confidentialité : on les demande à TikTok à l'ouverture.
        $infosTiktok = null;
        if ($comptes->has('tiktok')) {
            try {
                $infosTiktok = $tiktok->infos($comptes['tiktok']);
            } catch (\Throwable $e) {
                $infosTiktok = ['erreur' => $e->getMessage()];
            }
        }

        return view('publication.post-form', [
            'post'        => $post,
            'client'      => $client,
            'campagnes'   => $client->campaigns()->where('archived', false)->orderBy('name')->get(),
            'comptes'     => $comptes,
            'cibles'      => $post->exists ? $post->targets->keyBy('platform') : collect(),
            'medias'      => $client->mediaAssets()->latest()->get(),
            'choisis'     => $post->exists ? $post->media->pluck('id')->all() : [],
            'infosTiktok' => $infosTiktok,
        ]);
    }

    private function enregistrer(Request $request, Post $post, Client $client)
    {
        $d = $request->validate([
            'action'       => ['required', Rule::in(['brouillon', 'programmer', 'maintenant'])],
            'campaign_id'  => ['nullable', Rule::exists('campaigns', 'id')->where('client_id', $client->id)],
            'title'        => ['nullable', 'string', 'max:255'],
            'caption'      => ['nullable', 'string', 'max:63206'],
            'link_url'     => ['nullable', 'url', 'max:500'],
            'media_ids'    => ['array', 'max:10'],
            'media_ids.*'  => [Rule::exists('media_assets', 'id')->where('client_id', $client->id)],
            'jour'         => ['nullable', 'date_format:Y-m-d', 'required_if:action,programmer'],
            'heure'        => ['nullable', 'date_format:H:i', 'required_if:action,programmer'],
            'reseaux'      => ['array'],
            'reseaux.*.actif'   => ['nullable', 'boolean'],
            'reseaux.*.format'  => ['nullable', 'string', 'max:20'],
            'reseaux.*.texte'   => ['nullable', 'string', 'max:63206'],
            'reseaux.*.options' => ['nullable', 'array'],
        ], [
            'jour.required_if'  => 'Choisissez le jour de publication.',
            'heure.required_if' => 'Choisissez l’heure de publication.',
        ]);

        $quand = match ($d['action']) {
            'maintenant' => now(),
            'programmer' => Carbon::parse("{$d['jour']} {$d['heure']}", config('publication.fuseau'))->utc(),
            default      => ! empty($d['jour']) && ! empty($d['heure'])
                ? Carbon::parse("{$d['jour']} {$d['heure']}", config('publication.fuseau'))->utc()
                : null,
        };

        if ($d['action'] === 'programmer' && $quand->lt(now()->subMinute())) {
            return back()->withInput()->withErrors(['heure' => 'Cette heure est déjà passée. Choisissez « Publier maintenant » ou une heure à venir.']);
        }

        $comptes = $client->integrations()->where('active', true)->get()->keyBy('platform');
        $reseaux = collect($d['reseaux'] ?? [])
            ->filter(fn ($r, $p) => ! empty($r['actif']) && isset(Reseaux::CATALOGUE[$p]) && $comptes->has($p));

        if ($d['action'] !== 'brouillon' && $reseaux->isEmpty()) {
            return back()->withInput()->withErrors(['reseaux' => 'Choisissez au moins un réseau.']);
        }

        DB::transaction(function () use ($post, $d, $quand, $reseaux, $comptes) {
            $post->fill([
                'campaign_id'  => $d['campaign_id'] ?? null,
                'title'        => $d['title'] ?? null,
                'caption'      => $d['caption'] ?? null,
                'link_url'     => $d['link_url'] ?? null,
                'scheduled_at' => $quand,
                'status'       => 'brouillon',     // promu plus bas, une fois vérifié
            ])->save();

            $post->media()->sync(
                collect($d['media_ids'] ?? [])->values()->mapWithKeys(fn ($id, $i) => [$id => ['position' => $i]])->all()
            );

            // Les cibles retirées disparaissent ; les autres sont mises à jour.
            $post->targets()->whereNotIn('platform', $reseaux->keys())->delete();

            foreach ($reseaux as $plateforme => $r) {
                $formats = array_keys(Reseaux::CATALOGUE[$plateforme]['formats']);

                $post->targets()->updateOrCreate(
                    ['platform' => $plateforme],
                    [
                        'integration_id'   => $comptes[$plateforme]->id,
                        'format'           => in_array($r['format'] ?? null, $formats, true) ? $r['format'] : $formats[0],
                        'caption_override' => trim($r['texte'] ?? '') !== '' ? $r['texte'] : null,
                        'options'          => $this->options($plateforme, $r['options'] ?? []),
                        'status'           => 'en_attente',
                    ]
                );
            }
        });

        if ($d['action'] === 'brouillon') {
            return redirect()->route('posts.edit', $post)->with('ok', 'Brouillon enregistré.');
        }

        // Les vérifications de chaque réseau, avant de rien promettre.
        $post->load('targets.integration', 'media', 'campaign');
        $problemes = [];

        foreach ($post->targets as $cible) {
            $cible->setRelation('post', $post);

            foreach (Reseaux::publieur($cible->platform)->verifier($cible) as $probleme) {
                $problemes["reseaux.{$cible->platform}"][] = $probleme;
            }
        }

        if ($problemes) {
            return redirect()->route('posts.edit', $post)
                ->withErrors($problemes)
                ->with('ok', 'Enregistré en brouillon — à corriger avant de programmer.');
        }

        $post->update(['status' => 'programmee']);

        $message = $d['action'] === 'maintenant'
            ? 'Publication lancée : elle part dans la minute.'
            : 'Publication programmée le ' . $post->heureLocale()->translatedFormat('j F à H \h i') . '.';

        return redirect()->route('posts.show', $post)->with('ok', $message);
    }

    /**
     * Ne garde que les options connues de chaque réseau. Pour TikTok, les
     * cases non cochées valent « non » : TikTok exige que les interactions
     * soient désactivées par défaut et qu'aucune confidentialité ne soit
     * choisie à la place de la personne.
     */
    private function options(string $plateforme, array $o): array
    {
        $oui = fn ($cle) => filter_var($o[$cle] ?? false, FILTER_VALIDATE_BOOLEAN);

        return match ($plateforme) {
            'youtube' => [
                'titre'           => mb_substr(trim($o['titre'] ?? ''), 0, 100),
                'confidentialite' => in_array($o['confidentialite'] ?? '', ['public', 'unlisted', 'private'], true) ? $o['confidentialite'] : 'public',
                'ia'              => $oui('ia'),
            ],
            'tiktok' => [
                'confidentialite'    => in_array($o['confidentialite'] ?? '', ['PUBLIC_TO_EVERYONE', 'MUTUAL_FOLLOW_FRIENDS', 'FOLLOWER_OF_CREATOR', 'SELF_ONLY'], true) ? $o['confidentialite'] : null,
                'commentaires'       => $oui('commentaires'),
                'duo'                => $oui('duo'),
                'collage'            => $oui('collage'),
                'contenu_commercial' => $oui('contenu_commercial'),
                'votre_marque'       => $oui('contenu_commercial') && $oui('votre_marque'),
                'contenu_marque'     => $oui('contenu_commercial') && $oui('contenu_marque'),
                'ia'                 => $oui('ia'),
                'consentement'       => $oui('consentement'),
            ],
            default => [],
        };
    }
}
