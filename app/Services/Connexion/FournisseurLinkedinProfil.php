<?php

namespace App\Services\Connexion;

use Illuminate\Support\Facades\Http;

/**
 * Branchement du profil LinkedIn personnel de la personne qui se connecte
 * (réseau « linkedin_profil ») : un dirigeant, un porte-parole.
 *
 * Passe par l'application « profils » (Share on LinkedIn + OpenID Connect),
 * utilisable sans examen. Son jeton vit 60 jours, sans renouvellement : la
 * page Intégrations affiche la date limite. Tout le reste (échange de jetons,
 * renouvellement) est celui des Pages.
 */
class FournisseurLinkedinProfil extends FournisseurLinkedin
{
    protected const CONFIG     = 'linkedin_profil';
    protected const PLATEFORME = 'linkedin_profil';

    protected function comptes(string $jeton, array $accordees, array $commun): array
    {
        if (! in_array('w_member_social', $accordees, true)) {
            throw new \RuntimeException('LinkedIn n’a pas accordé la permission de publier sur le profil (w_member_social). L’application Profils doit avoir le produit « Share on LinkedIn ».');
        }

        $moi = Http::timeout(20)->withToken($jeton)->get('https://api.linkedin.com/v2/userinfo');

        if ($moi->failed() || ! $moi->json('sub')) {
            throw new \RuntimeException('LinkedIn a refusé la lecture du profil : ' . $this->api->motif($moi)
                . '. L’application Profils doit avoir le produit « Sign In with LinkedIn using OpenID Connect ».');
        }

        $urn = 'urn:li:person:' . $moi->json('sub');
        $nom = $moi->json('name') ?: trim($moi->json('given_name') . ' ' . $moi->json('family_name'));

        return [$commun + [
            'groupe'              => $urn,
            'libelle'             => "{$nom}  ·  profil personnel",
            'external_account_id' => $urn,
            'account_name'        => $nom,
            'settings'            => ['auteur' => $urn, 'type' => 'membre'],
        ]];
    }
}
