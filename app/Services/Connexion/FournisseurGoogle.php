<?php

namespace App\Services\Connexion;

use App\Models\Integration;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

/**
 * Branchement d'une chaîne YouTube par OAuth Google.
 *
 * Le jeton d'accès Google vit une heure ; c'est le jeton de renouvellement
 * (refresh_token) qui fait durer le branchement. Deux pièges, tous deux
 * neutralisés ici :
 *
 *   - Google ne remet un refresh_token qu'au PREMIER consentement. Si la
 *     personne rebranche un compte déjà autorisé, elle n'en reçoit plus — et le
 *     branchement casse une heure plus tard. `prompt=consent` force Google à
 *     redemander, donc à en redonner un.
 *   - Au renouvellement, Google ne renvoie généralement PAS de nouveau
 *     refresh_token. Écraser l'ancien par null couperait l'accès pour de bon.
 */
class FournisseurGoogle implements FournisseurOAuth
{
    /**
     * Seulement ce qui sert déjà : Google refuse de vérifier une application
     * qui demande une permission qu'elle n'utilise pas. Ajouter
     * yt-analytics.readonly le jour où la page Analytique lira YouTube — et
     * mettre à jour la politique de confidentialité du site en même temps.
     */
    private const SCOPES = [
        'https://www.googleapis.com/auth/youtube.upload',      // publier les vidéos programmées
        'https://www.googleapis.com/auth/youtube.readonly',    // lire le nom et l'id de la chaîne
    ];

    public function urlAutorisation(string $etat, string $retour): string
    {
        return 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query([
            'client_id'              => config('services.google.client_id'),
            'redirect_uri'           => $retour,
            'response_type'          => 'code',
            'scope'                  => implode(' ', self::SCOPES),
            'access_type'            => 'offline',
            'prompt'                 => 'consent',
            'include_granted_scopes' => 'true',
            'state'                  => $etat,
        ]);
    }

    public function recevoir(Request $request, string $retour): array
    {
        if ($request->filled('error')) {
            throw new \RuntimeException($request->query('error') === 'access_denied'
                ? 'L’accès à YouTube a été refusé : aucun compte n’a été branché.'
                : 'Google a interrompu le branchement : ' . $request->query('error') . '.');
        }

        $code = $request->query('code')
            ?? throw new \RuntimeException('Google n’a renvoyé aucun code d’autorisation.');

        $jetons = $this->demanderJetons([
            'grant_type'   => 'authorization_code',
            'code'         => $code,
            'redirect_uri' => $retour,
        ]) ?? throw new \RuntimeException('Google a jugé le code d’autorisation invalide ou déjà utilisé. Recommencez le branchement.');

        if (empty($jetons['refresh_token'])) {
            // Sans lui, le compte cesserait de fonctionner dans une heure, en
            // silence. Mieux vaut refuser le branchement tout de suite.
            throw new \RuntimeException('Google n’a pas remis de jeton de renouvellement. Retirez l’accès de Studio Machine dans votre compte Google, puis recommencez.');
        }

        $reponse = Http::timeout(20)
            ->withToken($jetons['access_token'])
            ->get('https://www.googleapis.com/youtube/v3/channels', [
                'part' => 'snippet',
                'mine' => 'true',
            ]);

        if ($reponse->failed()) {
            throw new \RuntimeException('YouTube a refusé la lecture des chaînes : ' . $this->motif($reponse->json()));
        }

        $chaines = $reponse->json('items', []);

        if (! $chaines) {
            throw new \RuntimeException('Ce compte Google n’a aucune chaîne YouTube. Créez-en une, ou branchez le compte Google qui la possède.');
        }

        return array_map(fn ($c) => [
            'groupe'              => $c['id'],
            'libelle'             => $c['snippet']['title'] ?? $c['id'],
            'platform'            => 'youtube',
            'external_account_id' => $c['id'],
            'account_name'        => $c['snippet']['title'] ?? null,
            'access_token'        => $jetons['access_token'],
            'refresh_token'       => $jetons['refresh_token'],
            'token_expires_at'    => now()->addSeconds((int) ($jetons['expires_in'] ?? 3600)),
            'scopes'              => isset($jetons['scope']) ? str_replace(' ', ',', $jetons['scope']) : null,
            'settings'            => ['channel_id' => $c['id']],
        ], $chaines);
    }

    public function rafraichirSiBesoin(Integration $integration): void
    {
        if ($integration->token_expires_at && $integration->token_expires_at->gt(now()->addMinutes(5))) {
            return;
        }

        if (! $integration->refresh_token) {
            throw $this->aRebrancher($integration);
        }

        $jetons = $this->demanderJetons([
            'grant_type'    => 'refresh_token',
            'refresh_token' => $integration->refresh_token,
        ]) ?? throw $this->aRebrancher($integration);

        $integration->update(array_filter([
            'access_token'     => $jetons['access_token'],
            'token_expires_at' => now()->addSeconds((int) ($jetons['expires_in'] ?? 3600)),
            // Rarement renvoyé ; on ne remplace l'ancien que si un nouveau arrive.
            'refresh_token'    => $jetons['refresh_token'] ?? null,
        ]));
    }

    // =====================================================================
    // Plomberie
    // =====================================================================

    /** Retourne null si Google répond invalid_grant ; lève une exception pour tout autre refus. */
    private function demanderJetons(array $params): ?array
    {
        $reponse = Http::timeout(20)->asForm()->post('https://oauth2.googleapis.com/token', $params + [
            'client_id'     => config('services.google.client_id'),
            'client_secret' => config('services.google.client_secret'),
        ]);

        $corps = $reponse->json() ?? [];

        // invalid_grant = accès révoqué, mot de passe changé, jeton expiré :
        // rien ne se réparera sans que la personne rebranche le compte.
        if (($corps['error'] ?? null) === 'invalid_grant') {
            return null;
        }

        if ($reponse->failed() || empty($corps['access_token'])) {
            throw new \RuntimeException('Google a refusé l’échange de jetons : '
                . ($corps['error_description'] ?? $corps['error'] ?? "HTTP {$reponse->status()}"));
        }

        return $corps;
    }

    private function aRebrancher(Integration $integration): \RuntimeException
    {
        $message = 'Le compte YouTube doit être rebranché (accès révoqué ou expiré).';

        // On le dit sur l'intégration, là où l'équipe le verra ; on ne la
        // désactive pas : le rebranchement remettra tout en place.
        $integration->update(['last_error' => $message]);

        return new \RuntimeException($message);
    }

    private function motif(?array $corps): string
    {
        $erreur = $corps['error'] ?? [];

        return trim(($erreur['message'] ?? 'motif inconnu')
            . (isset($erreur['errors'][0]['reason']) ? ' (' . $erreur['errors'][0]['reason'] . ')' : ''));
    }
}
