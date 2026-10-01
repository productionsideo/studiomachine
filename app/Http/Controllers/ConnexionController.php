<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\Integration;
use App\Services\Connexion\FournisseurGoogle;
use App\Services\Connexion\FournisseurMeta;
use App\Services\Connexion\FournisseurOAuth;
use App\Services\Connexion\FournisseurTiktok;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * « Connecter un compte » : l'aller-retour OAuth avec Meta, Google et TikTok.
 *
 * La partie commune vit ici :
 *   - l'état anti-falsification (un retour qui ne porte pas l'état qu'on a
 *     émis est rejeté — sinon n'importe quel lien pourrait brancher le compte
 *     d'un inconnu sur un de nos clients) ;
 *   - le client concerné, gardé en session pendant l'aller-retour ;
 *   - le choix du compte quand la personne en gère plusieurs ;
 *   - l'écriture dans `integrations`.
 *
 * Le client lui-même peut brancher ses comptes : c'est souvent lui qui détient
 * l'accès à sa Page ou à sa chaîne. Il n'en reçoit pas plus de droits pour
 * autant — la publication reste réservée à l'équipe.
 */
class ConnexionController extends Controller
{
    /** Le segment d'URL → la classe, et les réseaux qu'il branche. */
    private const FOURNISSEURS = [
        'meta'    => [FournisseurMeta::class, ['facebook', 'instagram']],
        'youtube' => [FournisseurGoogle::class, ['youtube']],
        'tiktok'  => [FournisseurTiktok::class, ['tiktok']],
    ];

    public function rediriger(Request $request, Client $client, string $fournisseur)
    {
        abort_unless($request->user()->canAccessClient($client->id), 403);
        abort_unless(isset(self::FOURNISSEURS[$fournisseur]), 404);

        $cle = $fournisseur === 'youtube' ? 'google' : $fournisseur;

        if (! config("services.{$cle}.client_id")) {
            return back()->with('ok', 'L’application développeur n’est pas encore configurée sur le serveur pour ce réseau.');
        }

        $etat = Str::random(40);

        $request->session()->put("oauth.{$etat}", [
            'client_id'   => $client->id,
            'fournisseur' => $fournisseur,
            'emis'        => now()->timestamp,
        ]);

        return redirect()->away($this->fournisseur($fournisseur)->urlAutorisation($etat, config("services.{$cle}.redirect")));
    }

    public function retour(Request $request, string $fournisseur)
    {
        abort_unless(isset(self::FOURNISSEURS[$fournisseur]), 404);

        $etat    = (string) $request->query('state');
        $attendu = $request->session()->pull("oauth.{$etat}");

        // Un état inconnu, d'un autre fournisseur, ou vieux de plus de 15 min.
        if (! $attendu || $attendu['fournisseur'] !== $fournisseur || now()->timestamp - $attendu['emis'] > 900) {
            return redirect()->route('integrations.index')
                ->with('ok', 'La connexion a expiré ou ne vient pas d’ici. Recommencez depuis la page Intégrations.');
        }

        $client = Client::findOrFail($attendu['client_id']);
        abort_unless($request->user()->canAccessClient($client->id), 403);

        $cle = $fournisseur === 'youtube' ? 'google' : $fournisseur;

        try {
            $comptes = $this->fournisseur($fournisseur)->recevoir($request, config("services.{$cle}.redirect"));
        } catch (\Throwable $e) {
            return redirect()->route('integrations.index', ['client' => $client->slug])->with('ok', $e->getMessage());
        }

        $groupes = collect($comptes)->groupBy('groupe');

        if ($groupes->isEmpty()) {
            return redirect()->route('integrations.index', ['client' => $client->slug])
                ->with('ok', 'Aucun compte utilisable n’a été trouvé avec cette connexion.');
        }

        if ($groupes->count() === 1) {
            $this->brancher($client, $groupes->first()->all());

            return redirect()->route('integrations.index', ['client' => $client->slug])
                ->with('ok', 'Compte connecté : ' . $groupes->first()->first()['libelle'] . '.');
        }

        // Plusieurs Pages ou chaînes : la personne choisit. Les jetons restent
        // côté serveur, chiffrés dans la session, jamais dans la page.
        $jeton = Str::random(32);
        $request->session()->put("oauth_choix.{$jeton}", encrypt([
            'client_id' => $client->id,
            'comptes'   => $comptes,
        ]));

        return view('publication.connexion-choix', [
            'client'  => $client,
            'jeton'   => $jeton,
            'groupes' => $groupes->map(fn ($g) => $g->first()['libelle']),
        ]);
    }

    public function choisir(Request $request)
    {
        $d = $request->validate(['jeton' => ['required', 'string'], 'groupe' => ['required', 'string']]);

        $chiffre = $request->session()->pull("oauth_choix.{$d['jeton']}");
        abort_unless($chiffre, 410, 'Choix expiré : recommencez la connexion.');

        $choix  = decrypt($chiffre);
        $client = Client::findOrFail($choix['client_id']);
        abort_unless($request->user()->canAccessClient($client->id), 403);

        $comptes = array_values(array_filter($choix['comptes'], fn ($c) => (string) $c['groupe'] === $d['groupe']));
        abort_if($comptes === [], 422);

        $this->brancher($client, $comptes);

        return redirect()->route('integrations.index', ['client' => $client->slug])
            ->with('ok', 'Compte connecté : ' . $comptes[0]['libelle'] . '.');
    }

    private function brancher(Client $client, array $comptes): void
    {
        foreach ($comptes as $c) {
            $integration = Integration::firstOrNew(['client_id' => $client->id, 'platform' => $c['platform']]);

            // Un nouveau branchement remplace l'ancien compte, mais on garde
            // les réglages qu'on ne connaît pas (ex. property_id de GA4).
            $integration->fill([
                'external_account_id' => $c['external_account_id'],
                'account_name'        => $c['account_name'],
                'access_token'        => $c['access_token'],
                'refresh_token'       => $c['refresh_token'] ?? $integration->refresh_token,
                'token_expires_at'    => $c['token_expires_at'] ?? null,
                'scopes'              => $c['scopes'] ?? null,
                'settings'            => array_merge($integration->settings ?? [], $c['settings'] ?? []),
                'active'              => true,
                'last_error'          => null,
            ])->save();
        }
    }

    private function fournisseur(string $nom): FournisseurOAuth
    {
        return app(self::FOURNISSEURS[$nom][0]);
    }
}
