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

    public function test_rattacher_les_codes_des_publicites_compte_leurs_visites_et_demandes(): void
    {
        $client = Client::first();
        $cols   = array_flip(\Illuminate\Support\Facades\Schema::getColumnListing('events'));
        foreach (range(1, 4) as $n) {
            DB::table('events')->insert(array_intersect_key(['client_id' => $client->id, 'type' => 'pageview', 'session_id' => "m{$n}",
                'utm_campaign' => '120254359283750368', 'utm_source' => 'fb', 'created_at' => now(), 'updated_at' => now()], $cols));
        }
        DB::table('leads')->insert(array_intersect_key(['client_id' => $client->id, 'utm_campaign' => '120254359283750368', 'external_id' => 'L2',
            'name' => 'C D', 'email' => 'c@d.e', 'status' => 'nouveau', 'submitted_at' => now(), 'created_at' => now(), 'updated_at' => now()],
            array_flip(\Illuminate\Support\Facades\Schema::getColumnListing('leads'))));

        $camp = $client->campaigns()->first();

        // Le formulaire montre le code vu sur le site, avec ses chiffres.
        $this->actingAs($this->admin)->get(route('campaigns.edit', $camp))
            ->assertOk()->assertSee('120254359283750368')->assertSee('Codes de suivi des publicités rattachées');

        $this->actingAs($this->admin)->patch(route('campaigns.update', $camp), [
            'name' => $camp->name, 'color' => '#9a0f20', 'codes_suivi' => ['120254359283750368'], 'codes_supplementaires' => '999, 888',
        ])->assertRedirect();

        $this->assertSame(['120254359283750368', '999', '888'], $camp->fresh()->codes_suivi);

        $k = app(\App\Services\VueEnsemble::class)->campagnes()->first();
        $this->assertSame(3 + 4, $k['visites']);     // son code + celui de la publicité
        $this->assertSame(1 + 1, $k['demandes']);

        // Un code déjà pris par une autre campagne du client est refusé.
        $autre = $client->campaigns()->create(['name' => 'Autre', 'utm_campaign' => 'autre', 'color' => '#000000']);
        $this->actingAs($this->admin)->patch(route('campaigns.update', $autre), [
            'name' => 'Autre', 'color' => '#000000', 'codes_suivi' => ['120254359283750368'],
        ])->assertSessionHasErrors('codes_suivi');
        $this->assertNull($autre->fresh()->codes_suivi);
    }
}
