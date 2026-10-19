<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Integration;
use App\Models\Post;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Le glisser-déposer du calendrier : déplacer ce qui n'est pas parti, et rien
 * d'autre.
 */
class CalendrierTest extends TestCase
{
    use RefreshDatabase;

    private Client $client;
    private Post $post;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = Client::create([
            'name' => 'Essai', 'slug' => 'essai', 'api_key_prefix' => 'sm_essai0000', 'api_key_hash' => 'x',
        ]);

        $fb = Integration::create([
            'client_id' => $this->client->id, 'platform' => 'facebook', 'access_token' => 'jeton',
            'settings' => ['page_id' => '111'], 'active' => true,
        ]);

        $this->post = Post::create([
            'client_id' => $this->client->id, 'caption' => 'Bonjour', 'status' => 'programmee',
            'scheduled_at' => now()->addDays(2),
        ]);
        $this->post->targets()->create(['integration_id' => $fb->id, 'platform' => 'facebook', 'format' => 'post']);

        $this->admin = User::create(['name' => 'Équipe', 'email' => 'a@essai.test', 'password' => 'x', 'role' => 'admin']);
    }

    private function deplacer(string $quand, ?User $qui = null)
    {
        return $this->actingAs($qui ?? $this->admin)
            ->patchJson(route('posts.deplacer', $this->post), ['quand' => $quand]);
    }

    public function test_l_equipe_deplace_une_publication_programmee(): void
    {
        $quand = now(config('publication.fuseau'))->addDays(3)->setTime(14, 15);

        $this->deplacer($quand->format('Y-m-d H:i'))
            ->assertOk()
            ->assertJson(['heure' => '14:15', 'jour' => $quand->toDateString()]);

        $this->assertTrue($this->post->fresh()->scheduled_at->eq($quand->copy()->utc()));
    }

    public function test_on_ne_deplace_pas_dans_le_passe(): void
    {
        $this->deplacer(now(config('publication.fuseau'))->subHour()->format('Y-m-d H:i'))->assertStatus(422);
    }

    public function test_une_publication_partie_ne_bouge_plus(): void
    {
        $this->post->targets()->update(['status' => 'en_cours']);
        $avant = $this->post->scheduled_at;

        $this->deplacer(now(config('publication.fuseau'))->addDays(4)->format('Y-m-d H:i'))->assertStatus(409);

        $this->assertTrue($this->post->fresh()->scheduled_at->eq($avant));
    }

    public function test_un_client_ne_deplace_rien(): void
    {
        $client = User::create([
            'name' => 'Client', 'email' => 'c@essai.test', 'password' => 'x',
            'role' => 'client', 'client_id' => $this->client->id,
        ]);

        $this->deplacer(now(config('publication.fuseau'))->addDays(4)->format('Y-m-d H:i'), $client)->assertForbidden();
    }

    public function test_les_vues_semaine_et_mois_s_affichent(): void
    {
        $jour = $this->post->heureLocale()->toDateString();

        $this->actingAs($this->admin)
            ->get(route('calendrier.index', ['semaine' => $jour]))
            ->assertOk()
            ->assertSee('data-post="' . $this->post->id . '"', false)
            ->assertSee('draggable="true"', false);

        $this->actingAs($this->admin)
            ->get(route('calendrier.index', ['vue' => 'mois', 'mois' => substr($jour, 0, 7)]))
            ->assertOk()
            ->assertSee('data-post="' . $this->post->id . '"', false);
    }
}
