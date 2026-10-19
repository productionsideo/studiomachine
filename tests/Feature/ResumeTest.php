<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Integration;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Le tableau de bord Résumé : collecte des statistiques par réseau, puis
 * affichage (équipe et client) et export.
 */
class ResumeTest extends TestCase
{
    use RefreshDatabase;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();

        config(['meta.version' => 'v25.0']);
        $this->client = Client::create(['name' => 'BJ21', 'slug' => 'bj21', 'api_key_prefix' => 'sm_bj21000000', 'api_key_hash' => 'x']);

        Integration::create(['client_id' => $this->client->id, 'platform' => 'facebook', 'access_token' => 'jp', 'active' => true, 'settings' => ['page_id' => 'P1']]);
        Integration::create(['client_id' => $this->client->id, 'platform' => 'instagram', 'access_token' => 'jp', 'active' => true, 'settings' => ['ig_user_id' => 'IG1']]);
        Integration::create(['client_id' => $this->client->id, 'platform' => 'youtube', 'access_token' => 'jy', 'active' => true,
            'refresh_token' => 'r', 'token_expires_at' => now()->addHour(), 'settings' => ['channel_id' => 'UC1']]);
        Integration::create(['client_id' => $this->client->id, 'platform' => 'tiktok', 'access_token' => 'jt', 'active' => true]);
    }

    /** $avant : réponses qui priment (Http::fake garde la première qui correspond). */
    private function fauxReseaux(array $avant = []): void
    {
        $hier = now()->subDay()->setTimezone('America/Los_Angeles')->startOfDay()->addDay()->toIso8601String();

        Http::fake($avant + [
            // Facebook
            'graph.facebook.com/v25.0/P1/insights*' => function ($r) use ($hier) {
                return match ($r['metric']) {
                    'page_media_view'       => Http::response(['data' => [['values' => [['end_time' => $hier, 'value' => 500]]]]]),
                    'page_post_engagements' => Http::response(['data' => [['values' => [['end_time' => $hier, 'value' => 40]]]]]),
                    default                 => Http::response(['error' => ['message' => 'metric deprecated', 'code' => 100]], 400),
                };
            },
            'graph.facebook.com/v25.0/P1/published_posts*' => Http::response(['data' => [[
                'id' => 'P1_9', 'created_time' => now()->subDays(2)->toIso8601String(), 'message' => 'Règle 1',
                'permalink_url' => 'https://fb/9', 'attachments' => ['data' => [['media_type' => 'video']]],
                'reactions' => ['summary' => ['total_count' => 12]], 'comments' => ['summary' => ['total_count' => 3]], 'shares' => ['count' => 2],
            ]]]),
            'graph.facebook.com/v25.0/P1_9/insights*' => Http::response(['data' => [['values' => [['value' => 1200]]]]]),
            'graph.facebook.com/v25.0/P1*' => Http::response(['followers_count' => 2314]),

            // Instagram
            'graph.facebook.com/v25.0/IG1/insights*' => Http::response(['data' => [
                ['name' => 'views', 'total_value' => ['value' => 300]],
                ['name' => 'reach', 'total_value' => ['value' => 120]],
                ['name' => 'total_interactions', 'total_value' => ['value' => 15]],
            ]]),
            'graph.facebook.com/v25.0/IG1/media*' => Http::response(['data' => [[
                'id' => 'M1', 'caption' => 'Reel', 'media_type' => 'VIDEO', 'media_product_type' => 'REELS',
                'permalink' => 'https://ig/m1', 'thumbnail_url' => 'https://ig/t.jpg', 'timestamp' => now()->subDay()->toIso8601String(),
                'like_count' => 20, 'comments_count' => 4,
            ]]]),
            'graph.facebook.com/v25.0/M1/insights*' => Http::response(['data' => [
                ['name' => 'views', 'values' => [['value' => 900]]], ['name' => 'total_interactions', 'values' => [['value' => 30]]],
            ]]),
            'graph.facebook.com/v25.0/IG1*' => Http::response(['followers_count' => 20]),

            // YouTube
            'www.googleapis.com/youtube/v3/channels*' => Http::response(['items' => [[
                'statistics' => ['subscriberCount' => '17', 'viewCount' => '6883'],
                'contentDetails' => ['relatedPlaylists' => ['uploads' => 'UU1']],
            ]]]),
            'www.googleapis.com/youtube/v3/playlistItems*' => Http::response(['items' => [['contentDetails' => ['videoId' => 'V1', 'videoPublishedAt' => now()->subDays(3)->toIso8601String()]]]]),
            'www.googleapis.com/youtube/v3/videos*' => Http::response(['items' => [[
                'id' => 'V1', 'snippet' => ['title' => 'Short', 'publishedAt' => now()->subDays(3)->toIso8601String(), 'thumbnails' => ['medium' => ['url' => 'https://yt/t.jpg']]],
                'statistics' => ['viewCount' => '450', 'likeCount' => '9', 'commentCount' => '1'], 'contentDetails' => ['duration' => 'PT45S'],
            ]]]),
        ]);
    }

    public function test_la_collecte_remplit_comptes_et_publications_sans_effacer_ce_qui_est_connu(): void
    {
        $this->fauxReseaux();

        $this->artisan('statistiques:collecter')->assertSuccessful();

        $aujourdhui = now(config('publication.fuseau'))->toDateString();
        $fb = DB::table('stats_comptes')->where('platform', 'facebook')->where('jour', $aujourdhui)->first();
        $this->assertSame(2314, (int) $fb->abonnes);
        $this->assertSame(500, (int) DB::table('stats_comptes')->where('platform', 'facebook')->whereNotNull('vues')->value('vues'));

        $this->assertSame(6883, (int) DB::table('stats_comptes')->where('platform', 'youtube')->value('vues_cumulees'));

        $this->assertSame(1200, (int) DB::table('stats_publications')->where('external_id', 'P1_9')->value('vues'));
        $this->assertSame(17, (int) DB::table('stats_publications')->where('external_id', 'P1_9')->value('interactions'));
        $this->assertSame('reel', DB::table('stats_publications')->where('external_id', 'M1')->value('type'));
        $this->assertSame('short', DB::table('stats_publications')->where('external_id', 'V1')->value('type'));

        // Deuxième passe : si Meta ne donne plus les vues, la valeur connue reste.
        $this->app->forgetInstance(\Illuminate\Http\Client\Factory::class);
        \Illuminate\Support\Facades\Facade::clearResolvedInstance(\Illuminate\Http\Client\Factory::class);
        $this->fauxReseaux(['graph.facebook.com/v25.0/P1/insights*' => Http::response(['error' => ['message' => 'gone', 'code' => 100]], 400)]);
        $this->artisan('statistiques:collecter', ['--client' => 'bj21']);
        Http::assertSent(fn ($r) => str_contains($r->url(), 'P1/insights') && str_contains($r->url(), 'page_media_view'));
        $this->assertSame(500, (int) DB::table('stats_comptes')->where('platform', 'facebook')->whereNotNull('vues')->value('vues'));
    }

    public function test_un_jeton_refuse_est_signale_sur_l_integration_sans_bloquer_les_autres(): void
    {
        $this->fauxReseaux(['graph.facebook.com/v25.0/P1/insights*' => Http::response(['error' => ['message' => 'Session expirée', 'code' => 190]], 400)]);

        $this->artisan('statistiques:collecter')->assertSuccessful();

        $this->assertStringStartsWith('Statistiques :', Integration::where('platform', 'facebook')->value('last_error'));
        $this->assertTrue(DB::table('stats_comptes')->where('platform', 'youtube')->exists());
    }

    public function test_la_page_s_affiche_pour_l_equipe_et_le_client_et_s_exporte(): void
    {
        $this->fauxReseaux();
        $this->artisan('statistiques:collecter');

        $admin = User::create(['name' => 'Équipe', 'email' => 'a@essai.test', 'password' => 'x', 'role' => 'admin']);
        $this->actingAs($admin)->get(route('resume.index', ['client' => 'bj21']))
            ->assertOk()
            ->assertSee('Abonnés')
            ->assertSee('2 351')                     // 2 314 + 20 + 17
            ->assertSee('Statistiques disponibles quand l’accès TikTok sera accordé', false)
            ->assertSee('Mettre à jour maintenant');

        $client = User::create(['name' => 'C', 'email' => 'c@essai.test', 'password' => 'x', 'role' => 'client', 'client_id' => $this->client->id]);
        $this->actingAs($client)->get(route('resume.index'))
            ->assertOk()
            ->assertDontSee('Mettre à jour maintenant');
        $this->actingAs($client)->post(route('resume.collecter', $this->client))->assertForbidden();

        $csv = $this->actingAs($client)->get(route('resume.exporter', ['jours' => 30]))->assertOk()->getContent();
        $this->assertStringContainsString('Règle 1', $csv);
        $this->assertStringContainsString('Short', $csv);
    }
}
