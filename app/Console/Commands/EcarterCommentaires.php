<?php

namespace App\Console\Commands;

use App\Models\Client;
use App\Models\SocialComment;
use Illuminate\Console\Command;

/**
 * Écarte en bloc les commentaires antérieurs à une date.
 *
 * Sert au démarrage d'une campagne : la boîte « à répondre » du dashboard
 * affiche tout ce qui porte le statut « aucune », y compris l'historique
 * rapatrié lors des premiers essais. Les commentaires de la campagne
 * arriveraient noyés dedans, et le seul moyen de nettoyer serait de cliquer
 * « écarter » sur chacun.
 *
 * Ce que la commande ne fait PAS, volontairement :
 *
 *   - elle ne supprime rien. « Écarté » est un statut, pas une suppression :
 *     le texte du commentaire, son auteur et son analyse restent en base et
 *     restent consultables par le filtre « tous ».
 *   - elle ne touche qu'aux commentaires encore au statut « aucune ». Une
 *     réponse déjà envoyée, ou une réponse en attente de validation humaine,
 *     n'est jamais écrasée : ce sont des décisions que quelqu'un a prises.
 *   - elle ne fait rien sans confirmation, sauf --force. Un basculement en
 *     bloc n'a pas de bouton « annuler » dans le dashboard.
 */
class EcarterCommentaires extends Command
{
    protected $signature = 'social:ecarter-avant
                            {date : Date limite (AAAA-MM-JJ). Tout commentaire publié AVANT ce jour est écarté.}
                            {--client= : Ne traiter qu\'un client (son id)}
                            {--force : Ne pas demander confirmation}';

    protected $description = 'Bascule en « ignorée » les commentaires sans réponse publiés avant une date. Ne supprime rien.';

    public function handle(): int
    {
        try {
            $limite = new \DateTimeImmutable($this->argument('date'));
        } catch (\Exception) {
            $this->error('Date illisible. Attendu : AAAA-MM-JJ, par exemple 2026-08-18.');

            return self::FAILURE;
        }

        // On borne au début du jour : « avant le 18 août » exclut le 18 août
        // en entier, pas seulement sa première seconde.
        $limite = $limite->setTime(0, 0);

        $client = null;

        if ($id = $this->option('client')) {
            $client = Client::find($id);

            if (! $client) {
                $this->error("Aucun client avec l'id {$id}.");

                return self::FAILURE;
            }
        }

        $requete = SocialComment::query()
            ->where('reply_status', 'aucune')
            ->where('posted_at', '<', $limite)
            ->when($client, fn ($q) => $q->where('client_id', $client->id));

        $nombre = (clone $requete)->count();

        if ($nombre === 0) {
            $this->info('Aucun commentaire à écarter — la boîte est déjà propre.');

            return self::SUCCESS;
        }

        $portee = $client ? "de {$client->name}" : 'de tous les clients';

        $this->line("{$nombre} commentaire(s) {$portee}, publiés avant le "
            . $limite->format('d/m/Y') . ', sont encore sans réponse.');

        // Le plus ancien et le plus récent du lot : de quoi vérifier d'un coup
        // d'œil qu'on ne s'apprête pas à écarter autre chose que ce qu'on croit.
        $plusAncien = (clone $requete)->min('posted_at');
        $plusRecent = (clone $requete)->max('posted_at');

        $this->line("Du {$plusAncien} au {$plusRecent}.");
        $this->newLine();

        if (! $this->option('force') && ! $this->confirm('Les basculer en « écartée » ?', false)) {
            $this->line('Rien n’a été modifié.');

            return self::SUCCESS;
        }

        $modifies = $requete->update(['reply_status' => 'ignoree']);

        $this->info("{$modifies} commentaire(s) écarté(s).");
        $this->line('Ils restent consultables dans le dashboard par le filtre « tous ».');

        return self::SUCCESS;
    }
}
