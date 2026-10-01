<?php

namespace App\Services\Publication;

/**
 * Ce qu'un réseau répond quand on lui confie une publication.
 *
 * Trois issues seulement, et la distinction entre les deux échecs est ce qui
 * protège le client d'une publication en double :
 *
 *   - `reessayable` : on est CERTAIN que rien n'est parti (refus avant envoi,
 *     réseau injoignable, limite de débit). On peut retenter.
 *   - échec définitif : soit le refus ne changera pas (permission, format),
 *     soit on ne sait pas si la publication est sortie. Dans le doute, on
 *     s'arrête et un humain regarde. Publier deux fois au nom d'un client est
 *     pire que ne pas publier.
 */
final class Etape
{
    private function __construct(
        public readonly string $issue,          // publiee, en_cours, echec
        public readonly ?string $jobId = null,
        public readonly ?string $postId = null,
        public readonly ?string $permalink = null,
        public readonly ?string $erreur = null,
        public readonly bool $reessayable = false,
    ) {}

    public static function publiee(string $postId, ?string $permalink = null): self
    {
        return new self('publiee', postId: $postId, permalink: $permalink);
    }

    /** La plateforme traite le fichier ; on repassera voir avec ce jobId. */
    public static function enCours(string $jobId): self
    {
        return new self('en_cours', jobId: $jobId);
    }

    public static function echec(string $erreur, bool $reessayable = false): self
    {
        return new self('echec', erreur: mb_substr($erreur, 0, 1000), reessayable: $reessayable);
    }
}
