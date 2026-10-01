<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Integration;
use App\Services\Ga4Collecte;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * La collecte GA4, avec une clé générée pour le test et des réponses
 * Google simulées — aucun appel réel.
 */
class Ga4Test extends TestCase
{
    use RefreshDatabase;

    private Integration $ga4;
    private string $clePublique;

    protected function setUp(): void
    {
        parent::setUp();

        $paire = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($paire, $privee);
        $this->clePublique = openssl_pkey_get_details($paire)['key'];

        $client = Client::create(['name' => 'Essai', 'slug' => 'essai', 'api_key_prefix' => 'sm_essai0000', 'api_key_hash' => 'x']);

        $this->ga4 = Integration::create([
            'client_id'    => $client->id,
            'platform'     => 'ga4',
            'access_token' => json_encode([
                'type' => 'service_account', 'client_email' => 'robot@essai.iam.gserviceaccount.com',
                'private_key' => $privee, 'token_uri' => 'https://oauth2.googleapis.com/token',
            ]),
            'settings' => ['property_id' => '553223905'],
            'active'   => true,
        ]);
    }

    private function rapport(array $dims, array $mets, array $lignes): array
    {
        return [
            'dimensionHeaders' => array_map(fn ($n) => ['name' => $n], $dims),
            'metricHeaders'    => array_map(fn ($n) => ['name' => $n, 'type' => 'TYPE_INTEGER'], $mets),
            'rows'             => array_map(fn ($l) => [
                'dimensionValues' => array_map(fn ($v) => ['value' => $v], array_slice($l, 0, count($dims))),
                'metricValues'    => array_map(fn ($v) => ['value' => $v], array_slice($l, count($dims))),
            ], $lignes),
            'rowCount' => count($lignes),
        ];
    }

    public function test_la_collecte_signe_un_jwt_valide_et_range_les_chiffres(): void
    {
        $jour = now(config('publication.fuseau'))->format('Ymd');

        Http::fake([
            'oauth2.googleapis.com/token' => Http::response(['access_token' => 'jeton-essai', 'expires_in' => 3600]),
            'analyticsdata.googleapis.com/*' => Http::sequence()
                ->push($this->rapport(
                    ['date', 'sessionSource', 'sessionMedium', 'sessionCampaignName'],
                    ['sessions', 'totalUsers', 'engagedSessions', 'keyEvents', 'ecommercePurchases', 'purchaseRevenue'],
                    [[$jour, 'meta', 'paid', 'bj21_lancement_oct2026', '40', '35', '22', '3', '2', '59.90'],
                     [$jour, 'google', 'organic', '(organic)', '12', '11', '7', '0', '0', '0']],
                ))
                ->push($this->rapport(['date', 'sessionCampaignName', 'sessionSource'], ['eventCount'],
                    [[$jour, 'bj21_lancement_oct2026', 'meta', '9']])),
        ]);

        [$trafic, $amazon] = app(Ga4Collecte::class)->collecter($this->ga4, 3);

        $this->assertSame([2, 1], [$trafic, $amazon]);
        $this->assertSame(40, (int) DB::table('ga4_trafic')->where('source', 'meta')->value('sessions'));
        $this->assertSame('59.90', number_format((float) DB::table('ga4_trafic')->where('source', 'meta')->value('revenus'), 2, '.', ''));
        $this->assertSame(9, (int) DB::table('ga4_evenements')->where('evenement', 'clic_amazon')->value('nombre'));
        $this->assertNotNull($this->ga4->fresh()->last_synced_at);

        // Le JWT envoyé à Google est signé par la clé du compte de service.
        Http::assertSent(function (Request $r) {
            if (! str_contains($r->url(), 'oauth2.googleapis.com')) {
                return false;
            }
            [$entete, $charge, $sig] = explode('.', $r['assertion']);
            $dec = fn ($s) => base64_decode(strtr($s, '-_', '+/'));
            $claims = json_decode($dec($charge), true);

            return openssl_verify("{$entete}.{$charge}", $dec($sig), $this->clePublique, OPENSSL_ALGO_SHA256) === 1
                && $claims['iss'] === 'robot@essai.iam.gserviceaccount.com'
                && $claims['scope'] === 'https://www.googleapis.com/auth/analytics.readonly';
        });

        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'properties/553223905:runReport')
            && $r->hasHeader('Authorization', 'Bearer jeton-essai'));
    }

    public function test_une_nouvelle_collecte_remplace_la_periode_sans_additionner(): void
    {
        $jour = now(config('publication.fuseau'))->format('Ymd');
        $reponse = fn ($n) => $this->rapport(
            ['date', 'sessionSource', 'sessionMedium', 'sessionCampaignName'],
            ['sessions', 'totalUsers', 'engagedSessions', 'keyEvents', 'ecommercePurchases', 'purchaseRevenue'],
            [[$jour, 'meta', 'paid', 'x', (string) $n, '1', '1', '0', '0', '0']],
        );
        $vide = $this->rapport(['date', 'sessionCampaignName', 'sessionSource'], ['eventCount'], []);

        Http::fake([
            'oauth2.googleapis.com/token' => Http::response(['access_token' => 'j']),
            'analyticsdata.googleapis.com/*' => Http::sequence()->push($reponse(10))->push($vide)->push($reponse(14))->push($vide),
        ]);

        app(Ga4Collecte::class)->collecter($this->ga4, 3);
        app(Ga4Collecte::class)->collecter($this->ga4, 3);

        $this->assertSame(1, DB::table('ga4_trafic')->count());
        $this->assertSame(14, (int) DB::table('ga4_trafic')->value('sessions'));
    }

    public function test_un_acces_refuse_explique_quoi_faire(): void
    {
        Http::fake([
            'oauth2.googleapis.com/token'   => Http::response(['access_token' => 'j']),
            'analyticsdata.googleapis.com/*' => Http::response(['error' => ['code' => 403, 'message' => 'User does not have sufficient permissions for this property.']], 403),
        ]);

        try {
            app(Ga4Collecte::class)->collecter($this->ga4, 3);
            $this->fail('Une exception était attendue.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('robot@essai.iam.gserviceaccount.com', $e->getMessage());
            $this->assertStringContainsString('Lecteur', $e->getMessage());
        }

        $this->assertStringContainsString('Lecteur', $this->ga4->fresh()->last_error);
    }
}
