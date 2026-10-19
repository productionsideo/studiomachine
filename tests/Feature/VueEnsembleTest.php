<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Integration;
use App\Models\Post;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * L'accueil de l'équipe : chaque campagne depuis son début, chaque client en
 * un clic ; et le menu Gestion (Clients, Demandes).
 */
class VueEnsembleTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $client = Client::create(['name' => 'CRD', 'slug' => 'crd', 'api_key_prefix' => 'sm_crd0000000', 'api_key_hash' => 'x']);
        $fb     = Integration::create(['client_id' => $client->id, 'platform' => 'facebook', 'access_token' => 'j', 'active' => true]);
        $camp   = $client->campaigns()->create(['name' => 'Toute à zéro', 'utm_campaign' => 'toute_a_zero', 'starts_on' => now()->subDays(10), 'ends_on' => now()->addDays(10), 'color' => '#9a0f20']);

        $post  = Post::create(['client_id' => $client->id, 'campaign_id' => $camp->id, 'status' => 'publiee', 'scheduled_at' => now()->subDays(2)]);
        $cible = $post->targets()->create(['integration_id' => $fb->id, 'platform' => 'facebook', 'format' => 'reel', 'status' => 'publiee', 'external_post_id' => 'P_1', 'published_at' => now()->subDays(2)]);
        Post::create(['client_id' => $client->id, 'campaign_id' => $camp->id, 'status' => 'brouillon']);

        DB::table('stats_publications')->insert([
            'integration_id' => $fb->id, 'client_id' => $client->id, 'post_target_id' => $cible->id, 'platform' => 'facebook',
            'external_id' => 'P_1', 'type' => 'video', 'publie_le' => now()->subDays(2), 'vues' => 4321, 'interactions' => 87,
            'collecte_le' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);

        foreach (['s1', 's2', 's3'] as $s) {
            DB::table('events')->insert(array_intersect_key(
                ['client_id' => $client->id, 'type' => 'pageview', 'session_id' => $s, 'utm_campaign' => 'toute_a_zero', 'created_at' => now(), 'updated_at' => now()],
                array_flip(\Illuminate\Support\Facades\Schema::getColumnListing('events')),
            ));
        }
        DB::table('leads')->insert(array_intersect_key(
            ['client_id' => $client->id, 'utm_campaign' => 'toute_a_zero', 'external_id' => 'L1', 'name' => 'A B', 'phone' => '1', 'email' => 'a@b.c',
             'status' => 'nouveau', 'submitted_at' => now(), 'created_at' => now(), 'updated_at' => now()],
            array_flip(\Illuminate\Support\Facades\Schema::getColumnListing('leads')),
        ));

        $this->admin = User::create(['name' => 'Équipe', 'email' => 'a@essai.test', 'password' => 'x', 'role' => 'admin']);
    }

    public function test_chaque_campagne_a_sa_carte_avec_ses_chiffres(): void
    {
        $page = $this->actingAs($this->admin)->get(route('dashboard'))->assertOk();

        $page->assertSee('Vue d’ensemble', false)
            ->assertSee('Toute à zéro')
            ->assertSee('En cours')
            ->assertSee('4 321')       // vues des publications reliées à la campagne
            ->assertSee('87')          // interactions
            ->assertSee('33,3 %');     // 1 demande sur 3 visites, par le code de suivi

        $k = app(\App\Services\VueEnsemble::class)->campagnes()->first();
        $this->assertSame(1, $k['publiees']);
        $this->assertSame(1, $k['brouillons']);
        $this->assertSame(['facebook' => 1], $k['reseaux']);
    }

    public function test_le_menu_gestion_et_la_page_clients(): void
    {
        $this->actingAs($this->admin)->get(route('clients.index'))
            ->assertOk()
            ->assertSee('Gestion')
            ->assertSee('Résumé réseaux')
            ->assertSee(route('calendrier.index', ['client' => 'crd']), false);

        $client = User::create(['name' => 'C', 'email' => 'c@essai.test', 'password' => 'x', 'role' => 'client', 'client_id' => Client::first()->id]);
        $this->actingAs($client)->get(route('leads.index'))
            ->assertOk()
            ->assertSee('Demandes')
            ->assertDontSee(route('clients.index'), false);
    }
}
