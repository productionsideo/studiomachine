<?php

namespace App\Services\Connexion;

use App\Models\Integration;
use Illuminate\Http\Request;

/**
 * Ce que chaque fournisseur (Meta, Google, TikTok) doit savoir faire pour
 * qu'un compte se branche d'un clic, au lieu d'un jeton collé à la main.
 *
 * Le contrôleur (ConnexionController) gère la partie commune : l'état anti-
 * falsification, le client concerné, le choix du compte quand il y en a
 * plusieurs, et l'écriture dans `integrations`. Le fournisseur, lui, ne parle
 * qu'à sa plateforme.
 */
interface FournisseurOAuth
{
    /** L'adresse où envoyer la personne pour qu'elle autorise l'accès. */
    public function urlAutorisation(string $etat, string $retour): string;

    /**
     * Échange le code reçu au retour contre des jetons, et liste les comptes
     * que ces jetons ouvrent. Lève une RuntimeException lisible en cas de refus.
     *
     * Chaque compte retourné :
     *   [
     *     'groupe'              => identifiant qui regroupe les comptes à brancher ensemble
     *                              (une Page Facebook et son Instagram),
     *     'libelle'             => ce que la personne voit pour choisir,
     *     'platform'            => facebook | instagram | youtube | tiktok,
     *     'external_account_id' => …,
     *     'account_name'        => …,
     *     'access_token'        => …,
     *     'refresh_token'       => … | null,
     *     'token_expires_at'    => Carbon | null,
     *     'scopes'              => 'a,b,c' | null,
     *     'settings'            => [...],
     *   ]
     */
    public function recevoir(Request $request, string $retour): array;

    /**
     * Renouvelle le jeton d'accès s'il expire bientôt. Ne fait rien si le
     * jeton est encore bon. Lève une RuntimeException si le renouvellement est
     * refusé (la personne doit alors rebrancher le compte).
     */
    public function rafraichirSiBesoin(Integration $integration): void;
}
