<?php

namespace App\Services\Statistiques;

use App\Models\Integration;
use Illuminate\Support\Carbon;

/**
 * Ce que chaque réseau sait rapporter pour le tableau de bord Résumé.
 *
 * Un collecteur ne lève d'exception que si le compte entier est illisible
 * (jeton refusé) ; un indicateur que le réseau ne fournit plus est
 * simplement absent (null) — Meta en retire régulièrement.
 */
interface Collecteur
{
    /**
     * Les chiffres du compte, jour par jour (dates dans le fuseau de l'équipe).
     *
     * @return array<string, array{abonnes?: ?int, vues?: ?int, portee?: ?int, interactions?: ?int, vues_cumulees?: ?int}>
     */
    public function jours(Integration $integration, Carbon $depuis): array;

    /**
     * Les publications sorties depuis $depuis, avec leurs chiffres à jour.
     *
     * @return list<array{external_id: string, type: string, publie_le: Carbon, texte: ?string, vignette: ?string, lien: ?string,
     *                    vues: ?int, portee: ?int, interactions: ?int, j_aime: ?int, commentaires: ?int, partages: ?int, enregistrements: ?int}>
     */
    public function publications(Integration $integration, Carbon $depuis): array;
}
