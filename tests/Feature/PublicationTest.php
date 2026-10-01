<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Integration;
use App\Models\Post;
use App\Models\PostTarget;
use App\Models\User;
use App\Services\Publication\Etape;
use App\Services\Publication\Publieur;
use App\Services\Publication\PublieurFacebook;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Ce qui ne doit jamais casser : publier à l'heure, ne jamais publier deux
 * fois, et ne retenter que ce qui peut l'être sans risque.
 */
class PublicationTest extends TestCase
{
    use RefreshDatabase;

    private Client $client;
    private Post $post;

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
            'scheduled_at' => now()->subMinute(),
        ]);

        $this->post->targets()->create(['integration_id' => $fb->id, 'platform' => 'facebook', 'format' => 'post']);
    }

    /** Remplace le publieur Facebook par un faux qui compte ses appels. */
    private function fauxPublieur(callable $demarrer, ?callable $poursuivre = null): object
    {
        $faux = new class($demarrer, $poursuivre) implements Publieur {
            public int $demarrages = 0;
            public function __construct(private $d, private $p) {}
            public function verifier(PostTarget $c): array { return []; }
            public function demarrer(PostTarget $c): Etape { $this->demarrages++; return ($this->d)($c); }
            public function poursuivre(PostTarget $c): Etape { return ($this->p)($c); }
        };

        $this->app->instance(PublieurFacebook::class, $faux);

        return $faux;
    }

    public function test_une_publication_due_part_et_le_statut_suit(): void
    {
        $this->fauxPublieur(fn () => Etape::publiee('42', 'https://fb/42'));

        $this->artisan('publications:envoyer')->assertSuccessful();

        $cible = $this->post->targets()->first();
        $this->assertSame('publiee', $cible->status);
        $this->assertSame('42', $cible->external_post_id);
        $this->assertSame('publiee', $this->post->fresh()->status);
    }

    public function test_rien_ne_part_avant_l_heure(): void
    {
        $this->post->update(['scheduled_at' => now()->addHour()]);
        $faux = $this->fauxPublieur(fn () => Etape::publiee('42'));

        $this->artisan('publications:envoyer');

        $this->assertSame(0, $faux->demarrages);
    }

    public function test_un_brouillon_ne_part_jamais(): void
    {
        $this->post->update(['status' => 'brouillon']);
        $faux = $this->fauxPublieur(fn () => Etape::publiee('42'));

        $this->artisan('publications:envoyer');

        $this->assertSame(0, $faux->demarrages);
    }

    public function test_une_cible_deja_reservee_n_est_pas_publiee_deux_fois(): void
    {
        // Une autre passe l'a déjà prise : elle est en_cours, sans job.
        $this->post->targets()->update(['status' => 'en_cours']);
        $faux = $this->fauxPublieur(fn () => Etape::publiee('42'));

        $this->artisan('publications:envoyer');
        $this->artisan('publications:envoyer');

        $this->assertSame(0, $faux->demarrages);
    }

    public function test_un_echec_passager_est_retente_plus_tard(): void
    {
        $faux = $this->fauxPublieur(fn () => Etape::echec('limite de débit', reessayable: true));

        $this->artisan('publications:envoyer');

        $cible = $this->post->targets()->first();
        $this->assertSame('en_attente', $cible->status);
        $this->assertTrue($cible->next_attempt_at->isFuture());

        // Pas avant le délai…
        $this->artisan('publications:envoyer');
        $this->assertSame(1, $faux->demarrages);

        // … puis oui.
        Carbon::setTestNow(now()->addMinutes(3));
        $this->artisan('publications:envoyer');
        $this->assertSame(2, $faux->demarrages);
    }

    public function test_un_echec_definitif_n_est_jamais_retente(): void
    {
        $faux = $this->fauxPublieur(fn () => Etape::echec('permission refusée'));

        $this->artisan('publications:envoyer');
        Carbon::setTestNow(now()->addHour());
        $this->artisan('publications:envoyer');

        $this->assertSame(1, $faux->demarrages);
        $this->assertSame('echec', $this->post->targets()->first()->status);
        $this->assertSame('echec', $this->post->fresh()->status);
    }

    public function test_une_video_en_traitement_est_suivie_jusqu_a_la_publication(): void
    {
        $this->fauxPublieur(fn () => Etape::enCours('job-1'), fn () => Etape::publiee('99'));

        $this->artisan('publications:envoyer');
        $this->assertSame('en_cours', $this->post->targets()->first()->status);

        Carbon::setTestNow(now()->addMinutes(2));
        $this->artisan('publications:envoyer');

        $this->assertSame('publiee', $this->post->targets()->first()->status);
    }

    public function test_un_client_consulte_mais_ne_publie_pas(): void
    {
        $user = User::create([
            'name' => 'Client', 'email' => 'c@essai.test', 'password' => 'x',
            'role' => 'client', 'client_id' => $this->client->id,
        ]);

        $this->actingAs($user)->get(route('posts.show', $this->post))->assertOk();
        $this->actingAs($user)->get(route('calendrier.index'))->assertOk();
        $this->actingAs($user)->get(route('posts.create'))->assertForbidden();
        $this->actingAs($user)->get(route('posts.edit', $this->post))->assertForbidden();
    }

    public function test_un_lien_porte_le_reseau_et_la_campagne(): void
    {
        $campagne = $this->client->campaigns()->create(['name' => 'Automne', 'utm_campaign' => 'automne']);
        $this->post->update(['link_url' => 'https://exemple.ca/page', 'campaign_id' => $campagne->id]);

        $lien = $this->post->fresh()->lienSuivi('facebook');

        $this->assertStringContainsString('utm_source=facebook', $lien);
        $this->assertStringContainsString('utm_campaign=automne', $lien);
        $this->assertStringContainsString('utm_content=sm' . $this->post->id, $lien);
    }
}
