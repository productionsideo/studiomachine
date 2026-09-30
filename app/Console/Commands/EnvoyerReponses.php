<?php

namespace App\Console\Commands;

use App\Models\SocialComment;
use App\Services\GraphMeta;
use Illuminate\Console\Command;

/**
 * Vide la file des réponses validées mais non encore publiées.
 *
 * Une réponse tombe en `en_attente` dans deux cas :
 *
 *   — un humain l'a validée dans le dashboard alors que la plateforme était
 *     injoignable (jeton expiré, Meta en panne) ;
 *   — l'envoi automatique a été refusé par Meta.
 *
 * Dans les deux cas, la réponse existe, elle est approuvée, et elle n'est pas
 * partie. C'est exactement la situation qu'il ne faut jamais laisser silencieuse :
 * quelqu'un croit avoir répondu à un client, et personne n'a répondu. Cette
 * commande repasse et publie.
 *
 * Elle est idempotente : une réponse publiée passe en `envoyee` et ne repasse
 * plus jamais ici. Une réponse refusée reste en attente avec le motif du refus
 * — visible dans la boîte de réception, pas enfouie dans un journal.
 */
class EnvoyerReponses extends Command
{
    protected $signature = 'social:repondre {--limite=50}';

    protected $description = 'Publie les réponses validées qui attendent encore (échec d’envoi, jeton expiré)';

    public function handle(GraphMeta $graph): int
    {
        $enAttente = SocialComment::query()
            ->where('reply_status', 'en_attente')
            ->whereNotNull('reply_text')
            ->whereIn('platform', ['facebook', 'instagram'])
            ->limit((int) $this->option('limite'))
            ->get();

        if ($enAttente->isEmpty()) {
            $this->info('Aucune réponse en attente.');

            return self::SUCCESS;
        }

        $partis = 0;
        $bloques = 0;

        foreach ($enAttente as $commentaire) {
            [$ok, $resultat] = $graph->repondre($commentaire, $commentaire->reply_text);

            if ($ok) {
                $commentaire->update([
                    'reply_status'      => 'envoyee',
                    'replied_at'        => now(),
                    'external_reply_id' => $resultat,
                    'reply_error'       => null,
                ]);
                $partis++;

                continue;
            }

            // On garde le statut « en attente » : la réponse n'est pas perdue,
            // elle sera retentée. Mais le motif est inscrit, pour qu'on sache
            // qu'il se passe quelque chose plutôt que de croire à un envoi.
            $commentaire->update(['reply_error' => $resultat]);
            $bloques++;

            $this->error("  ✗ #{$commentaire->id} ({$commentaire->platform}) : {$resultat}");
        }

        $this->info("{$partis} publiée(s), {$bloques} encore bloquée(s).");

        return self::SUCCESS;
    }
}
