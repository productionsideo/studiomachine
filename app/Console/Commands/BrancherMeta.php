<?php

namespace App\Console\Commands;

use App\Models\Client;
use App\Models\Integration;
use App\Services\GraphMeta;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

/**
 * Branche la Page Facebook et le compte Instagram d'un client, à partir d'un
 * jeton d'utilisateur pris dans l'explorateur Graph.
 *
 * Pourquoi une commande et pas un bouton
 * --------------------------------------
 * Le vrai flux OAuth (redirection, callback, consentement) suppose une app Meta
 * en production, validée. Tant qu'on travaille en mode développement sur les
 * actifs du portefeuille Business, l'explorateur Graph donne un jeton en trente
 * secondes. Cette commande transforme ce jeton d'une heure en accès durable.
 *
 * Les trois jetons de Meta, qu'il faut absolument distinguer
 * ---------------------------------------------------------
 *   1. Jeton d'utilisateur COURT   — celui de l'explorateur. Vit une heure.
 *   2. Jeton d'utilisateur LONG    — 60 jours. Obtenu par échange (fb_exchange_token).
 *   3. Jeton de PAGE               — dérivé du jeton long. Il N'EXPIRE PAS.
 *
 * C'est le troisième qu'on veut, et lui seul. Beaucoup d'intégrations stockent
 * le deuxième et cassent silencieusement au bout de deux mois — le connecteur
 * cesse alors de rapatrier les commentaires, sans que personne ne s'en aperçoive
 * avant qu'un client se plaigne de n'avoir jamais eu de réponse.
 */
class BrancherMeta extends Command
{
    protected $signature = 'meta:brancher
                            {--client= : Identifiant du client}
                            {--jeton= : Jeton d\'utilisateur pris dans l\'explorateur Graph}
                            {--page= : Identifiant de la Page, si le compte en administre plusieurs}';

    protected $description = 'Branche une Page Facebook et son compte Instagram à partir d’un jeton d’explorateur Graph';

    public function handle(GraphMeta $graph): int
    {
        $client = Client::find($this->option('client'));

        if (! $client) {
            $this->error('Client introuvable. Utilisez --client=<id>.');
            $this->line('Clients existants : ' . Client::pluck('name', 'id')->map(
                fn ($nom, $id) => "{$id} = {$nom}"
            )->implode(', '));

            return self::FAILURE;
        }

        $jetonCourt = (string) $this->option('jeton');

        if ($jetonCourt === '') {
            $this->error('Jeton manquant. Utilisez --jeton=<jeton de l’explorateur Graph>.');

            return self::FAILURE;
        }

        // --- 1. Un jeton d'une heure ne sert à rien. On l'échange. ----------

        $this->line('Échange du jeton court contre un jeton long…');

        [$ok, $jetonLong] = $graph->prolonger($jetonCourt);

        if (! $ok) {
            $this->error("Meta a refusé l’échange : {$jetonLong}");
            $this->line('Vérifiez META_APP_ID et META_APP_SECRET dans le .env.');

            return self::FAILURE;
        }

        $this->info('  ✓ Jeton long obtenu (60 jours).');

        // --- 2. Les Pages, et leur jeton permanent --------------------------

        $version = config('meta.version');

        $reponse = Http::timeout(20)->get("https://graph.facebook.com/{$version}/me/accounts", [
            'fields'       => 'id,name,access_token,instagram_business_account{id,username}',
            'access_token' => $jetonLong,
        ])->json();

        if (isset($reponse['error'])) {
            $this->error('Meta a refusé : ' . ($reponse['error']['message'] ?? 'motif inconnu'));

            return self::FAILURE;
        }

        $pages = $reponse['data'] ?? [];

        // /me/accounts ne remonte que les Pages administrées EN PROPRE. Une Page
        // détenue par un portefeuille Business — le cas de tous les vrais clients —
        // n'y figure pas. On va alors la chercher par le portefeuille, ce qui exige
        // business_management.
        if ($pages === []) {
            $this->warn('  · Aucune Page en administration directe. Recherche par portefeuille Business…');
            $pages = $this->pagesParBusiness($jetonLong, $version);
        }

        if ($pages === []) {
            $this->newLine();
            $this->error('Ce jeton ne donne accès à aucune Page.');
            $this->newLine();
            $this->line('Trois causes possibles, dans l’ordre de fréquence :');
            $this->line('  1. La permission business_management n’a pas été accordée. Sans elle,');
            $this->line('     Meta ne montre pas les Pages détenues par un portefeuille Business —');
            $this->line('     et il ne dit pas « refusé », il renvoie une liste vide.');
            $this->line('  2. Votre compte n’a aucun rôle sur la Page dans le Business Manager.');
            $this->line('  3. La Page n’a pas été ajoutée aux actifs de l’application Meta.');

            return self::FAILURE;
        }

        $this->newLine();
        $this->line('Pages accessibles avec ce jeton :');
        foreach ($pages as $p) {
            $ig = $p['instagram_business_account']['username'] ?? null;
            $this->line("  • {$p['name']}  (id {$p['id']})" . ($ig ? "  — Instagram @{$ig}" : '  — aucun Instagram rattaché'));
        }
        $this->newLine();

        // --- 3. Choisir la Page ---------------------------------------------

        if (count($pages) > 1 && ! $this->option('page')) {
            $this->error('Plusieurs Pages : précisez laquelle avec --page=<id>.');

            return self::FAILURE;
        }

        $page = $this->option('page')
            ? collect($pages)->firstWhere('id', $this->option('page'))
            : $pages[0];

        if (! $page) {
            $this->error('Cette Page n’est pas dans la liste ci-dessus.');

            return self::FAILURE;
        }

        if (empty($page['access_token'])) {
            $this->error('Meta n’a pas renvoyé de jeton pour cette Page — permission pages_show_list manquante ?');

            return self::FAILURE;
        }

        // --- 4. Enregistrer ---------------------------------------------------

        Integration::updateOrCreate(
            ['client_id' => $client->id, 'platform' => 'facebook'],
            [
                'external_account_id' => $page['id'],
                'account_name'        => $page['name'],
                'access_token'        => $page['access_token'],   // chiffré par le modèle
                'token_expires_at'    => null,                    // un jeton de Page n'expire pas
                'settings'            => ['page_id' => $page['id']],
                'active'              => true,
                'last_error'          => null,
            ],
        );

        $this->info("  ✓ Facebook branché : {$page['name']}");

        $ig = $page['instagram_business_account'] ?? null;

        if ($ig) {
            Integration::updateOrCreate(
                ['client_id' => $client->id, 'platform' => 'instagram'],
                [
                    'external_account_id' => $ig['id'],
                    'account_name'        => '@' . ($ig['username'] ?? '?'),
                    'access_token'        => $page['access_token'],   // le même jeton de Page ouvre Instagram
                    'token_expires_at'    => null,
                    'settings'            => ['ig_user_id' => $ig['id'], 'page_id' => $page['id']],
                    'active'              => true,
                    'last_error'          => null,
                ],
            );

            $this->info("  ✓ Instagram branché : @{$ig['username']}");
        } else {
            $this->warn('  · Aucun compte Instagram professionnel rattaché à cette Page.');
            $this->line('    Instagram doit être en compte Professionnel ET lié à la Page dans');
            $this->line('    les paramètres de la Page. Sinon, l’API Graph ne le voit pas du tout.');
        }

        // --- 5. Vérifier ce qu'on a vraiment ---------------------------------

        $this->newLine();
        $this->line('Vérification du jeton auprès de Meta…');

        $integration = Integration::where('client_id', $client->id)->where('platform', 'facebook')->first();
        $etat        = $graph->diagnostiquer($integration);

        if (! $etat['ok']) {
            $this->error('  ✗ Meta juge ce jeton invalide : ' . ($etat['motif'] ?? 'motif inconnu'));

            return self::FAILURE;
        }

        $this->info('  ✓ Jeton valide.');
        $this->line('  Expiration  : ' . ($etat['expire_le']?->format('Y-m-d H:i') ?? 'jamais — c’est bien un jeton de Page'));
        $this->line('  Permissions : ' . implode(', ', $etat['permissions'] ?: ['(aucune)']));

        // Chaque permission fait une chose précise, et l'absence de l'une se
        // manifeste soit par une erreur, soit — pire — par une liste vide.
        $requises = [
            'pages_show_list'         => 'voir la Page',
            'business_management'     => 'voir la Page quand elle appartient à un portefeuille Business',
            'pages_read_engagement'   => 'lire ce que la PAGE publie',
            'pages_read_user_content' => 'lire ce que les GENS écrivent — les commentaires',
            'pages_manage_engagement' => 'répondre aux commentaires',
        ];

        $manquent = array_diff(array_keys($requises), $etat['permissions'] ?? []);

        if ($manquent) {
            $this->newLine();
            $this->warn('Permissions manquantes :');
            foreach ($manquent as $p) {
                $this->line("  • {$p}  —  {$requises[$p]}");
            }
            $this->newLine();
            $this->line('Ces permissions n’appartiennent pas au cas d’utilisation « publicités ».');
            $this->line('Ajoutez à l’application le cas d’utilisation qui gère les PAGES, puis');
            $this->line('régénérez le jeton dans l’explorateur.');
        }

        $this->newLine();
        $this->info("Prêt. Lancez : php artisan social:synchroniser --client={$client->id} --sans-envoi");

        return self::SUCCESS;
    }

    /**
     * Cherche les Pages par les portefeuilles Business, quand /me/accounts est vide.
     *
     * Une Page d'entreprise réelle appartient presque toujours à un portefeuille,
     * pas à un compte personnel. Deux liens à explorer :
     *
     *   owned_pages  — les Pages que le portefeuille possède (le cas de CRD si
     *                  vous gérez son Business).
     *   client_pages — les Pages qu'un client vous a confiées sans vous en céder
     *                  la propriété (le cas d'une agence).
     *
     * Le jeton de Page ne figure pas sur ces liens : il faut le demander Page par
     * Page. C'est un appel de plus, mais c'est ce jeton-là — et lui seul — qui
     * n'expire jamais.
     */
    private function pagesParBusiness(string $jetonLong, string $version): array
    {
        $portefeuilles = Http::timeout(20)
            ->get("https://graph.facebook.com/{$version}/me/businesses", [
                'fields'       => 'id,name',
                'access_token' => $jetonLong,
            ])
            ->json();

        if (isset($portefeuilles['error'])) {
            $this->warn('  · Portefeuilles inaccessibles : ' . $portefeuilles['error']['message']);

            return [];
        }

        $trouvees = [];

        foreach ($portefeuilles['data'] ?? [] as $business) {
            $this->line("    Portefeuille : {$business['name']}");

            foreach (['owned_pages', 'client_pages'] as $lien) {
                $reponse = Http::timeout(20)
                    ->get("https://graph.facebook.com/{$version}/{$business['id']}/{$lien}", [
                        'fields'       => 'id,name',
                        'access_token' => $jetonLong,
                    ])
                    ->json();

                foreach ($reponse['data'] ?? [] as $page) {
                    // Le jeton de Page se demande sur la Page elle-même.
                    $detail = Http::timeout(20)
                        ->get("https://graph.facebook.com/{$version}/{$page['id']}", [
                            'fields'       => 'id,name,access_token,instagram_business_account{id,username}',
                            'access_token' => $jetonLong,
                        ])
                        ->json();

                    if (isset($detail['error']) || empty($detail['access_token'])) {
                        $this->warn("      · {$page['name']} : pas de jeton de Page ("
                            . ($detail['error']['message'] ?? 'aucun rôle sur cette Page ?') . ')');

                        continue;
                    }

                    $trouvees[] = $detail;
                }
            }
        }

        return $trouvees;
    }
}
