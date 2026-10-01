<?php

namespace App\Services\Connexion;

use App\Models\Integration;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Branchement d'un compte TikTok par le Login Kit (OAuth 2.0 v2).
 *
 * Les deux jetons de TikTok
 * -------------------------
 *   - jeton d'accès      : 24 heures ;
 *   - jeton de rafraîchissement : 365 jours, et TikTok peut en remettre un
 *     NOUVEAU à chaque renouvellement. Il faut donc réécrire les deux à chaque
 *     fois : garder l'ancien, c'est perdre le compte dans un an sans prévenir.
 *
 * PKCE
 * ----
 * La documentation du Login Kit web ne l'exige pas clairement (il l'est pour
 * le bureau et le mobile). On l'envoie quand même : TikTok l'accepte, et cela
 * protège l'échange du code si l'adresse de retour venait à fuiter.
 */
class FournisseurTiktok implements FournisseurOAuth
{
    private const AUTORISATION = 'https://www.tiktok.com/v2/auth/authorize/';
    private const JETON        = 'https://open.tiktokapis.com/v2/oauth/token/';
    private const PROFIL       = 'https://open.tiktokapis.com/v2/user/info/';

    public const PORTEES = 'user.info.basic,video.publish,video.upload,video.list';

    public function urlAutorisation(string $etat, string $retour): string
    {
        $verificateur = Str::random(64);
        session()->put('tiktok_pkce', $verificateur);

        $defi = rtrim(strtr(base64_encode(hash('sha256', $verificateur, true)), '+/', '-_'), '=');

        return self::AUTORISATION . '?' . http_build_query([
            'client_key'            => config('services.tiktok.client_id'),
            'response_type'         => 'code',
            'scope'                 => self::PORTEES,
            'redirect_uri'          => $retour,
            'state'                 => $etat,
            'code_challenge'        => $defi,
            'code_challenge_method' => 'S256',
        ]);
    }

    public function recevoir(Request $request, string $retour): array
    {
        if ($request->filled('error')) {
            throw new \RuntimeException('TikTok a refusé l’autorisation : '
                . ($request->query('error_description') ?: $request->query('error')));
        }

        $code = $request->query('code')
            ?? throw new \RuntimeException('TikTok n’a pas renvoyé de code d’autorisation.');

        // Le vérificateur ne sert qu'une fois : on le retire tout de suite.
        $verificateur = session()->pull('tiktok_pkce');

        $jetons = $this->demanderJeton(array_filter([
            'code'          => $code,
            'grant_type'    => 'authorization_code',
            'redirect_uri'  => $retour,
            'code_verifier' => $verificateur,
        ]));

        $profil = Http::timeout(20)
            ->withToken($jetons['access_token'])
            ->get(self::PROFIL, ['fields' => 'open_id,display_name,username,avatar_url'])
            ->json();

        if (($profil['error']['code'] ?? 'ok') !== 'ok') {
            throw new \RuntimeException('TikTok refuse de donner le profil du compte : '
                . ($profil['error']['message'] ?? 'motif inconnu'));
        }

        $utilisateur = $profil['data']['user'] ?? [];
        $openId      = $utilisateur['open_id'] ?? $jetons['open_id'];
        $pseudo      = $utilisateur['username'] ?? null;
        $nom         = $pseudo ? '@' . $pseudo : ($utilisateur['display_name'] ?? 'Compte TikTok');

        return [[
            'groupe'              => $openId,
            'libelle'             => $nom,
            'platform'            => 'tiktok',
            'external_account_id' => $openId,
            'account_name'        => $nom,
            'access_token'        => $jetons['access_token'],
            'refresh_token'       => $jetons['refresh_token'] ?? null,
            'token_expires_at'    => now()->addSeconds((int) ($jetons['expires_in'] ?? 86400)),
            'scopes'              => $jetons['scope'] ?? null,
            'settings'            => array_filter([
                'open_id'            => $openId,
                'username'           => $pseudo,
                'avatar_url'         => $utilisateur['avatar_url'] ?? null,
                'refresh_expires_at' => now()->addSeconds((int) ($jetons['refresh_expires_in'] ?? 31536000))->toIso8601String(),
            ]),
        ]];
    }

    public function rafraichirSiBesoin(Integration $integration): void
    {
        if ($integration->token_expires_at && $integration->token_expires_at->gt(now()->addMinutes(10))) {
            return;
        }

        if (! $integration->refresh_token) {
            throw new \RuntimeException('Le compte TikTok doit être rebranché (aucun jeton de renouvellement).');
        }

        try {
            $jetons = $this->demanderJeton([
                'grant_type'    => 'refresh_token',
                'refresh_token' => $integration->refresh_token,
            ]);
        } catch (\RuntimeException $e) {
            $integration->update(['last_error' => 'Le compte TikTok doit être rebranché.']);

            throw new \RuntimeException('Le compte TikTok doit être rebranché : ' . $e->getMessage());
        }

        $reglages = $integration->settings ?? [];
        if (isset($jetons['refresh_expires_in'])) {
            $reglages['refresh_expires_at'] = now()->addSeconds((int) $jetons['refresh_expires_in'])->toIso8601String();
        }

        $integration->update([
            'access_token'     => $jetons['access_token'],
            // TikTok peut remettre un nouveau jeton de renouvellement : c'est
            // lui qui fait foi désormais.
            'refresh_token'    => $jetons['refresh_token'] ?? $integration->refresh_token,
            'token_expires_at' => now()->addSeconds((int) ($jetons['expires_in'] ?? 86400)),
            'settings'         => $reglages,
        ]);
    }

    /** Appel au point de jeton ; TikTok y répond à plat, sans enveloppe `data`. */
    private function demanderJeton(array $parametres): array
    {
        $reponse = Http::timeout(20)->asForm()->post(self::JETON, $parametres + [
            'client_key'    => config('services.tiktok.client_id'),
            'client_secret' => config('services.tiktok.client_secret'),
        ]);

        $corps = $reponse->json() ?? [];

        if (empty($corps['access_token'])) {
            throw new \RuntimeException(sprintf(
                'TikTok a refusé le jeton : %s',
                $corps['error_description'] ?? $corps['error'] ?? "HTTP {$reponse->status()}",
            ));
        }

        return $corps;
    }
}
