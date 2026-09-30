<?php

namespace App\Console\Commands;

use App\Models\SocialComment;
use App\Services\AssistantClaude;
use Illuminate\Console\Command;

// Fait lire à Claude les commentaires qu'il n'a pas encore vus.
//
// Cette commande ne sert à rien tant qu'aucun réseau n'est connecté : il
// n'arrive aucun commentaire. Elle est écrite maintenant pour que, le jour où
// la synchronisation démarre, il n'y ait qu'une ligne de cron à ajouter,
// toutes les 10 minutes :
//
//     */10 * * * * cd /home/studiomachine/gestion-app && php artisan assistant:analyser
//
// (La syntaxe cron est en commentaire de ligne, pas en bloc : « */10 » dans un
//  bloc /* … */ le refermerait au mauvais endroit et casserait le fichier.)
//
// La limite existe pour ne pas vider un budget d'API sur un import massif.
class AnalyserCommentaires extends Command
{
    protected $signature = 'assistant:analyser {--limite=50 : Nombre de commentaires par passage}';

    protected $description = 'Fait analyser par Claude les commentaires non encore lus';

    public function handle(AssistantClaude $assistant): int
    {
        if (! $assistant->actif()) {
            $this->warn('Aucune clé Anthropic (ANTHROPIC_API_KEY) — rien à faire.');

            return self::SUCCESS;
        }

        $commentaires = SocialComment::whereNull('ai_analyzed_at')
            ->where('reply_status', 'aucune')
            ->orderBy('posted_at')
            ->limit((int) $this->option('limite'))
            ->get();

        if ($commentaires->isEmpty()) {
            $this->info('Aucun commentaire à analyser.');

            return self::SUCCESS;
        }

        $auto = 0;
        $echecs = 0;

        foreach ($commentaires as $commentaire) {
            $assistant->analyser($commentaire);

            $commentaire->ai_error ? $echecs++ : null;
            $commentaire->ai_auto_ok ? $auto++ : null;
        }

        $this->info(sprintf(
            '%d commentaire(s) analysé(s) — %d répondu(s) automatiquement, %d à valider, %d échec(s).',
            $commentaires->count(),
            $auto,
            $commentaires->count() - $auto - $echecs,
            $echecs,
        ));

        return self::SUCCESS;
    }
}
