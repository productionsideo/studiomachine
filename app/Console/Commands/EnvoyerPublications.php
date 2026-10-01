<?php

namespace App\Console\Commands;

use App\Models\Post;
use App\Models\PostTarget;
use App\Services\Publication\Etape;
use App\Services\Publication\Reseaux;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Publie ce qui est dû, et suit ce qui est en traitement chez les réseaux.
 *
 * Lancée chaque minute par le planificateur (routes/console.php). Une passe
 * ne fait que deux choses :
 *
 *   1. DÉMARRER  — les cibles en attente dont l'heure est venue ;
 *   2. POURSUIVRE — les cibles qu'une plateforme est en train de traiter.
 *
 * Chaque cible est « réservée » avant d'être touchée : on la fait passer
 * d'un état à l'autre par une requête conditionnelle, et seul le processus
 * qui a réellement modifié la ligne continue. Deux passes qui se
 * chevaucheraient (une minute lente, un lancement manuel) ne peuvent donc
 * jamais publier deux fois la même chose.
 */
class EnvoyerPublications extends Command
{
    protected $signature = 'publications:envoyer
                            {--cible= : Ne traiter qu\'une cible (son id), quelle que soit son heure}';

    protected $description = 'Publie les publications programmées dont l’heure est venue';

    public function handle(): int
    {
        $maintenant = now();

        $aDemarrer = PostTarget::query()
            ->where('status', 'en_attente')
            ->where(fn ($q) => $q->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', $maintenant))
            ->whereHas('post', fn ($q) => $q
                ->whereIn('status', ['programmee', 'en_cours', 'partielle'])
                ->where('scheduled_at', '<=', $maintenant))
            ->when($this->option('cible'), fn ($q, $id) => $q->whereKey($id))
            ->with('post.media', 'post.campaign', 'integration')
            ->limit(20)
            ->get();

        foreach ($aDemarrer as $cible) {
            // Réservation : seul celui qui fait passer la ligne de en_attente
            // à en_cours a le droit de publier.
            $reservee = PostTarget::whereKey($cible->id)
                ->where('status', 'en_attente')
                ->update([
                    'status'          => 'en_cours',
                    'attempts'        => $cible->attempts + 1,
                    'started_at'      => $cible->started_at ?? $maintenant,
                    'next_attempt_at' => null,
                ]);

            if (! $reservee) {
                continue;
            }

            $cible->refresh();
            $this->traiter($cible, demarrage: true);
        }

        $aSuivre = PostTarget::query()
            ->where('status', 'en_cours')
            ->whereNotNull('external_job_id')
            ->where('next_attempt_at', '<=', $maintenant)
            ->when($this->option('cible'), fn ($q, $id) => $q->whereKey($id))
            ->with('post.media', 'integration')
            ->limit(40)
            ->get();

        foreach ($aSuivre as $cible) {
            $reservee = PostTarget::whereKey($cible->id)
                ->where('status', 'en_cours')
                ->where('next_attempt_at', $cible->next_attempt_at)
                ->update(['next_attempt_at' => $maintenant->copy()->addMinutes(5)]);

            if (! $reservee) {
                continue;
            }

            $this->traiter($cible, demarrage: false);
        }

        if ($aDemarrer->isEmpty() && $aSuivre->isEmpty()) {
            $this->line('Rien à publier.');
        }

        return self::SUCCESS;
    }

    private function traiter(PostTarget $cible, bool $demarrage): void
    {
        $etiquette = "#{$cible->post_id} → " . Reseaux::nom($cible->platform);

        try {
            $publieur = Reseaux::publieur($cible->platform);

            if ($demarrage) {
                $problemes = $publieur->verifier($cible);

                $etape = $problemes
                    ? Etape::echec(implode(' ', $problemes))
                    : $publieur->demarrer($cible);
            } elseif ($this->traitementTropLong($cible)) {
                $etape = Etape::echec(sprintf(
                    'La plateforme traite la vidéo depuis plus de %d minutes. Vérifiez sur le compte si elle a fini par sortir avant de relancer.',
                    config('publication.traitement_max_minutes'),
                ));
            } else {
                $etape = $publieur->poursuivre($cible);
            }
        } catch (\Throwable $e) {
            // Une exception imprévue ne dit pas si la publication est partie.
            // Dans le doute : échec définitif, jamais de nouvel essai seul.
            Log::error("Publication {$etiquette} : exception", ['erreur' => $e->getMessage()]);
            $etape = Etape::echec('Erreur inattendue : ' . $e->getMessage());
        }

        $this->appliquer($cible, $etape);

        match ($etape->issue) {
            'publiee'  => $this->info("  ✓ {$etiquette} publiée"),
            'en_cours' => $this->line("  … {$etiquette} en traitement"),
            default    => $this->error("  ✗ {$etiquette} : {$etape->erreur}"),
        };
    }

    private function appliquer(PostTarget $cible, Etape $etape): void
    {
        if ($etape->issue === 'publiee') {
            $cible->update([
                'status'           => 'publiee',
                'external_post_id' => $etape->postId,
                'permalink'        => $etape->permalink,
                'published_at'     => now(),
                'next_attempt_at'  => null,
                'last_error'       => null,
            ]);
        } elseif ($etape->issue === 'en_cours') {
            $cible->update([
                'status'          => 'en_cours',
                'external_job_id' => $etape->jobId,
                'next_attempt_at' => now()->addSeconds(config('publication.delai_traitement_secondes')),
            ]);
        } elseif ($etape->reessayable && $cible->attempts < config('publication.essais_max')) {
            $delais = config('publication.delai_essai_minutes');
            $delai  = $delais[min($cible->attempts - 1, count($delais) - 1)];

            $cible->update([
                'status'          => 'en_attente',
                'external_job_id' => null,
                'next_attempt_at' => now()->addMinutes($delai),
                'last_error'      => $etape->erreur . " — nouvel essai dans {$delai} min.",
            ]);
        } else {
            $cible->update([
                'status'          => 'echec',
                'next_attempt_at' => null,
                'last_error'      => $etape->erreur,
            ]);
        }

        Post::find($cible->post_id)?->recalculerStatut();
    }

    private function traitementTropLong(PostTarget $cible): bool
    {
        return $cible->started_at
            && $cible->started_at->diffInMinutes(now()) > config('publication.traitement_max_minutes');
    }
}
