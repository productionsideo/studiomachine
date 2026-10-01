<?php

namespace App\Services\Publication;

use App\Models\PostTarget;

/**
 * Ce que chaque réseau doit savoir faire pour publier.
 *
 * Toutes les plateformes vidéo sont asynchrones : on dépose le fichier, elles
 * le traitent pendant une à plusieurs minutes, puis seulement on publie (ou on
 * apprend que c'est publié). D'où deux temps, plutôt qu'un appel qui
 * attendrait — un processus PHP bloqué dix minutes sur un serveur partagé est
 * un processus qu'on finit par tuer au mauvais moment.
 */
interface Publieur
{
    /** Ce qui empêche cette cible de partir, avant même d'appeler le réseau. */
    public function verifier(PostTarget $cible): array;

    /** Premier envoi. */
    public function demarrer(PostTarget $cible): Etape;

    /** Suite d'un envoi resté en_cours (cible->external_job_id). */
    public function poursuivre(PostTarget $cible): Etape;
}
