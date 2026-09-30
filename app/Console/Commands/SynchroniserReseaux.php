<?php

namespace App\Console\Commands;

use App\Models\Integration;
use App\Models\SocialComment;
use App\Services\AssistantClaude;
use App\Services\GraphMeta;
use Illuminate\Console\Command;

/**
 * Rapatrie les commentaires Facebook et Instagram, les fait lire par Claude,
 * et envoie les réponses qui ont le droit de partir seules.
 *
 * Les trois temps sont volontairement séparés et dans cet ordre :
 *
 *   1. LIRE     — on écrit d'abord tout en base. Si Claude tombe ensuite, ou si
 *                 Meta refuse un envoi, le commentaire est déjà sauvé. Rien de
 *                 ce qu'un client a écrit ne se perd à cause d'une panne chez
 *                 nous.
 *   2. ANALYSER — Claude classe et rédige. Il ne publie rien.
 *   3. RÉPONDRE — le code PHP décide, plateforme par plateforme.
 *
 * Chaque étape est isolée : l'échec de l'une n'empêche pas les autres. Une
 * synchronisation qui n'aboutit qu'à moitié vaut mieux qu'une qui échoue en
 * bloc et recommence à zéro à chaque fois.
 *
 * À planifier dans le cron du compte (attention : ne PAS écrire l'expression
 * cron dans un bloc de commentaire PHP — la séquence étoile-barre le fermerait
 * au milieu). Toutes les 15 minutes suffit largement : un commentaire n'est pas
 * une urgence, et Meta compte nos appels.
 */
class SynchroniserReseaux extends Command
{
    protected $signature = 'social:synchroniser
                            {--client= : Ne traiter qu\'un client (son id)}
                            {--plateforme= : facebook ou instagram}
                            {--analyser : Faire aussi lire les nouveaux commentaires par Claude (coûte des crédits)}
                            {--repondre : Publier les réponses que la politique autorise à partir seules}';

    protected $description = 'Rapatrie les commentaires Meta. N’appelle Claude que si on le demande explicitement.';

    public function handle(GraphMeta $graph, AssistantClaude $assistant): int
    {
        $integrations = Integration::query()
            ->whereIn('platform', ['facebook', 'instagram'])
            ->where('active', true)
            ->when($this->option('client'), fn ($q, $c) => $q->where('client_id', $c))
            ->when($this->option('plateforme'), fn ($q, $p) => $q->where('platform', $p))
            ->with('client')
            ->get();

        if ($integrations->isEmpty()) {
            $this->warn('Aucun compte Facebook ou Instagram connecté. Rien à faire.');

            return self::SUCCESS;
        }

        // --- 1. Lire -------------------------------------------------------

        foreach ($integrations as $integration) {
            [$neufs, $vus] = $graph->synchroniser($integration);

            $etiquette = "{$integration->client->name} / {$integration->platform}";

            if ($integration->fresh()->last_error) {
                $this->error("  ✗ {$etiquette} : {$integration->fresh()->last_error}");
                continue;
            }

            $this->line("  ✓ {$etiquette} : {$neufs} nouveau(x), {$vus} déjà connu(s)");
        }

        // --- 2. Analyser — SEULEMENT si on le demande ------------------------
        //
        // Par défaut, Claude ne touche à rien. Une Page reçoit énormément de
        // commentaires qui n'appellent aucune réponse : des gens qui s'étiquettent
        // entre eux, des disputes entre inconnus, du hors-sujet. Sur les 102
        // premiers commentaires de CRD, un sur cinq était dans ce cas. Les faire
        // tous lire par Claude, c'est payer pour des brouillons que personne
        // n'ouvrira jamais.
        //
        // L'analyse se déclenche donc à la demande, depuis le dashboard, sur le
        // commentaire qu'un humain a jugé digne d'une réponse. Le tri, c'est
        // gratuit quand c'est l'œil qui le fait.

        if (! $this->option('analyser')) {
            $enAttente = SocialComment::whereNull('ai_analyzed_at')
                ->where('reply_status', 'aucune')
                ->when($this->option('client'), fn ($q, $c) => $q->where('client_id', $c))
                ->count();

            $this->newLine();
            $this->info("{$enAttente} commentaire(s) en boîte, non analysé(s).");
            $this->line('Claude n’a pas été appelé — aucun crédit dépensé.');
            $this->line('Cliquez « Demander à Claude » dans le dashboard sur ceux qui méritent');
            $this->line('une réponse, ou relancez avec --analyser pour tout faire lire.');

            return self::SUCCESS;
        }

        if (! $assistant->actif()) {
            $this->warn('Claude n’est pas configuré : les commentaires sont en boîte, mais sans analyse.');

            return self::SUCCESS;
        }

        $aLire = SocialComment::query()
            ->whereIn('platform', ['facebook', 'instagram'])
            ->whereNull('ai_analyzed_at')
            ->whereNotNull('text')
            ->where('reply_status', 'aucune')
            ->when($this->option('client'), fn ($q, $c) => $q->where('client_id', $c))
            ->with('client')
            ->limit(50)
            ->get();

        $this->line("Analyse de {$aLire->count()} commentaire(s)…");

        foreach ($aLire as $commentaire) {
            $assistant->analyser($commentaire);
        }

        // --- 3. Répondre — SEULEMENT si on le demande ------------------------
        //
        // Deux verrous, et non un seul : il faut passer --repondre ici, ET que
        // la réponse automatique soit activée sur le client. Publier au nom de
        // quelqu'un est irréversible ; on ne le fait pas par défaut.

        if (! $this->option('repondre')) {
            $this->info('Rien n’a été publié (ajoutez --repondre pour autoriser l’envoi automatique).');

            return self::SUCCESS;
        }

        $partis = 0;

        // On relit depuis la base : analyser() a écrit ai_auto_ok, et c'est
        // cette colonne — pas la mémoire du processus — qui fait foi.
        $candidats = SocialComment::query()
            ->whereIn('platform', ['facebook', 'instagram'])
            ->where('reply_status', 'aucune')
            ->where('ai_auto_ok', true)
            ->whereNotNull('ai_draft')
            ->when($this->option('client'), fn ($q, $c) => $q->where('client_id', $c))
            ->get();

        foreach ($candidats as $commentaire) {
            [$ok, $resultat] = $graph->repondre($commentaire, $commentaire->ai_draft);

            $commentaire->update($ok ? [
                'reply_status'      => 'envoyee',
                'reply_text'        => $commentaire->ai_draft,
                'replied_by_ai'     => true,
                'replied_at'        => now(),
                'external_reply_id' => $resultat,
                'reply_error'       => null,
            ] : [
                // L'échec ne fait pas disparaître la réponse : elle repasse en
                // attente, visible dans la boîte, avec le motif du refus.
                'reply_status' => 'en_attente',
                'reply_text'   => $commentaire->ai_draft,
                'reply_error'  => $resultat,
            ]);

            $ok ? $partis++ : $this->error("  ✗ Envoi refusé (#{$commentaire->id}) : {$resultat}");
        }

        $this->info("{$partis} réponse(s) publiée(s) automatiquement.");

        $enAttente = SocialComment::where('reply_status', 'aucune')
            ->whereNotNull('ai_draft')
            ->where('ai_auto_ok', false)
            ->count();

        if ($enAttente > 0) {
            $this->warn("{$enAttente} commentaire(s) attendent une validation humaine (sujet sensible).");
        }

        return self::SUCCESS;
    }
}
