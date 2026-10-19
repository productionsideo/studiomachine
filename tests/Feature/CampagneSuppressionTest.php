<?php

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\Client;
use App\Models\Integration;
use App\Models\Post;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Supprimer une campagne : ses publications non parties peuvent suivre, celles
 * qui sont en ligne jamais.
 */
class CampagneSuppressionTest extends TestCase
{
    use RefreshDatabase;

    private Campaign $campagne;
    private Post $brouillon;
    private Post $enLigne;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $client = Client::create(['name' => 'Essai', 'slug' => 'essai', 'api_key_prefix' => 'sm_essai0000', 'api_key_hash' => 'x']);
        $fb = Integration::create(['client_id' => $client->id, 'platform' => 'facebook', 'access_token' => 'j', 'active' => true]);
        $this->campagne = $client->campaigns()->create(['name' => 'Automne', 'utm_campaign' => 'automne']);

        $this->brouillon = Post::create(['client_id' => $client->id, 'campaign_id' => $this->campagne->id, 'status' => 'brouillon']);
        $this->brouillon->targets()->create(['integration_id' => $fb->id, 'platform' => 'facebook', 'format' => 'post']);

        $this->enLigne = Post::create(['client_id' => $client->id, 'campaign_id' => $this->campagne->id, 'status' => 'publiee', 'scheduled_at' => now()->subDay()]);
        $this->enLigne->targets()->create(['integration_id' => $fb->id, 'platform' => 'facebook', 'format' => 'post', 'status' => 'publiee']);

        $this->admin = User::create(['name' => 'Équipe', 'email' => 'a@essai.test', 'password' => 'x', 'role' => 'admin']);
    }

    public function test_par_defaut_les_publications_restent_sans_campagne(): void
    {
        $this->actingAs($this->admin)->delete(route('campaigns.destroy', $this->campagne))->assertRedirect();

        $this->assertModelMissing($this->campagne);
        $this->assertNull($this->brouillon->fresh()->campaign_id);
        $this->assertNull($this->enLigne->fresh()->campaign_id);
    }

    public function test_sur_demande_les_publications_non_parties_suivent_mais_pas_celles_en_ligne(): void
    {
        $this->actingAs($this->admin)
            ->delete(route('campaigns.destroy', $this->campagne), ['publications' => 'supprimer'])
            ->assertRedirect()
            ->assertSessionHas('ok', 'Campagne « Automne » supprimée et 1 publication non partie.');

        $this->assertModelMissing($this->brouillon);
        $this->assertModelExists($this->enLigne);
        $this->assertNull($this->enLigne->fresh()->campaign_id);
    }

    public function test_un_client_ne_supprime_rien(): void
    {
        $client = User::create(['name' => 'C', 'email' => 'c@essai.test', 'password' => 'x', 'role' => 'client', 'client_id' => $this->campagne->client_id]);

        $this->actingAs($client)->delete(route('campaigns.destroy', $this->campagne))->assertForbidden();
        $this->assertModelExists($this->campagne);
    }

    public function test_la_zone_de_suppression_apparait_dans_la_modification(): void
    {
        $this->actingAs($this->admin)->get(route('campaigns.edit', $this->campagne))
            ->assertOk()
            ->assertSee('Supprimer la campagne');
    }
}
