<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Integration;
use App\Models\MediaAsset;
use App\Models\Post;
use App\Models\PostTarget;
use App\Services\Connexion\FournisseurLinkedin;
use App\Services\Publication\PublieurLinkedin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * LinkedIn : ne rien rendre visible avant l'appel à /rest/posts, et ne jamais
 * retenter cet appel-là quand on ignore s'il a abouti.
 */
class LinkedinTest extends TestCase
{
    use RefreshDatabase;

    private const AUTEUR = 'urn:li:organization:2414183';

    private PostTarget $cible;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.linkedin.version' => '202610', 'publication.medias.dossier' => sys_get_temp_dir()]);

        $client = Client::create([
            'name' => 'Essai', 'slug' => 'essai', 'api_key_prefix' => 'sm_essai0000', 'api_key_hash' => 'x',
        ]);

        $integration = Integration::create([
            'client_id' => $client->id, 'platform' => 'linkedin', 'access_token' => 'jeton',
            'external_account_id' => self::AUTEUR, 'settings' => ['auteur' => self::AUTEUR, 'type' => 'organisation'],
            'token_expires_at' => now()->addDays(30), 'active' => true,
        ]);

        $post = Post::create([
            'client_id' => $client->id, 'caption' => 'Lancement (enfin) : 50 % de rabais_ #promo', 'status' => 'programmee',
            'scheduled_at' => now(),
        ]);

        $this->cible = $post->targets()->create(['integration_id' => $integration->id, 'platform' => 'linkedin', 'format' => 'post']);
    }

    private function joindreVideo(int $taille = 6_000_000): void
    {
        $nom = 'essai-linkedin.mp4';
        file_put_contents(sys_get_temp_dir() . '/' . $nom, str_repeat('v', $taille));

        $video = MediaAsset::create([
            'client_id' => $this->cible->post->client_id, 'kind' => 'video', 'mime' => 'video/mp4', 'disk' => 'local',
            'filename' => $nom, 'original_name' => $nom, 'size_bytes' => $taille, 'duration_seconds' => 20, 'width' => 1080, 'height' => 1920,
        ]);

        $this->cible->post->media()->attach($video->id, ['position' => 0]);
        $this->cible->load('post.media');
    }

    public function test_un_texte_part_echappe_avec_la_version_de_l_api(): void
    {
        Http::fake(['api.linkedin.com/rest/posts' => Http::response('', 201, ['x-restli-id' => 'urn:li:share:99'])]);

        $etape = app(PublieurLinkedin::class)->demarrer($this->cible);

        $this->assertSame('publiee', $etape->issue);
        $this->assertSame('urn:li:share:99', $etape->postId);
        $this->assertSame('https://www.linkedin.com/feed/update/urn:li:share:99/', $etape->permalink);

        Http::assertSent(fn (Request $r) => $r->hasHeader('Linkedin-Version', '202610')
            && $r['author'] === self::AUTEUR
            // Parenthèses et soulignés échappés, mot-clic intact.
            && $r['commentary'] === 'Lancement \(enfin\) : 50 % de rabais\_ #promo'
            && ! isset($r['content']));
    }

    public function test_une_erreur_serveur_a_la_publication_n_est_pas_retentee(): void
    {
        Http::fake(['api.linkedin.com/rest/posts' => Http::response(['message' => 'Internal error'], 500)]);

        $etape = app(PublieurLinkedin::class)->demarrer($this->cible);

        $this->assertSame('echec', $etape->issue);
        $this->assertFalse($etape->reessayable);
    }

    public function test_une_video_est_deposee_par_parties_puis_publiee_une_fois_traitee(): void
    {
        $this->joindreVideo();

        $statut = 'PROCESSING';

        Http::fake([
            'api.linkedin.com/rest/videos?action=initializeUpload' => Http::response(['value' => [
                'video'              => 'urn:li:video:V1',
                'uploadToken'        => '',
                'uploadInstructions' => [
                    ['firstByte' => 0, 'lastByte' => 4194303, 'uploadUrl' => 'https://www.linkedin.com/dms-uploads/p1'],
                    ['firstByte' => 4194304, 'lastByte' => 5999999, 'uploadUrl' => 'https://www.linkedin.com/dms-uploads/p2'],
                ],
            ]]),
            'www.linkedin.com/dms-uploads/*' => Http::sequence()
                ->push('', 200, ['ETag' => '"etag-1"'])
                ->push('', 200, ['ETag' => '"etag-2"']),
            'api.linkedin.com/rest/videos?action=finalizeUpload' => Http::response('', 200),
            'api.linkedin.com/rest/videos/*' => function () use (&$statut) {
                return Http::response(['id' => 'urn:li:video:V1', 'status' => $statut]);
            },
            'api.linkedin.com/rest/posts' => Http::response('', 201, ['x-restli-id' => 'urn:li:ugcPost:7']),
        ]);

        $publieur = app(PublieurLinkedin::class);

        $etape = $publieur->demarrer($this->cible);
        $this->assertSame('en_cours', $etape->issue);
        $this->assertSame('urn:li:video:V1', $etape->jobId);

        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'dms-uploads/p2') && strlen($r->body()) === 1_805_696);
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'finalizeUpload')
            && $r['finalizeUploadRequest']['uploadedPartIds'] === ['etag-1', 'etag-2']);
        Http::assertNotSent(fn (Request $r) => str_ends_with($r->url(), '/rest/posts'));

        // Toujours en traitement : on attend, sans publier.
        $this->cible->external_job_id = $etape->jobId;
        $this->assertSame('en_cours', $publieur->poursuivre($this->cible)->issue);
        Http::assertNotSent(fn (Request $r) => str_ends_with($r->url(), '/rest/posts'));

        $statut = 'AVAILABLE';
        $fin = $publieur->poursuivre($this->cible);

        $this->assertSame('publiee', $fin->issue);
        $this->assertSame('urn:li:ugcPost:7', $fin->postId);
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/rest/posts') && $r['content']['media']['id'] === 'urn:li:video:V1');
    }

    public function test_une_video_mov_est_refusee_avant_envoi(): void
    {
        $this->joindreVideo();
        $this->cible->post->media->first()->mime = 'video/quicktime';

        $this->assertContains('LinkedIn n’accepte que les vidéos MP4.', app(PublieurLinkedin::class)->verifier($this->cible));
    }

    public function test_la_connexion_d_un_profil_donne_son_urn_et_la_date_limite(): void
    {
        config(['services.linkedin.client_id' => 'id', 'services.linkedin.client_secret' => 'secret']);

        Http::fake([
            'www.linkedin.com/oauth/v2/accessToken' => Http::response([
                'access_token' => 'jeton-neuf', 'expires_in' => 5184000, 'scope' => 'openid,profile,w_member_social',
            ]),
            'api.linkedin.com/v2/userinfo' => Http::response(['sub' => 'abC123', 'name' => 'Bruno Essai']),
        ]);

        $comptes = app(FournisseurLinkedin::class)->recevoir(
            \Illuminate\Http\Request::create('/retour', 'GET', ['code' => 'c', 'state' => 's']),
            'https://studiomachine.ca/gestion/integrations/linkedin/retour',
        );

        $this->assertCount(1, $comptes);
        $this->assertSame('urn:li:person:abC123', $comptes[0]['external_account_id']);
        $this->assertSame('Bruno Essai', $comptes[0]['account_name']);
        $this->assertNull($comptes[0]['refresh_token']);
        $this->assertTrue($comptes[0]['token_expires_at']->between(now()->addDays(59), now()->addDays(61)));
    }

    public function test_sans_jeton_de_renouvellement_un_acces_expire_demande_un_rebranchement(): void
    {
        $this->cible->integration->update(['token_expires_at' => now()->subMinute()]);
        Http::fake();

        $etape = app(PublieurLinkedin::class)->demarrer($this->cible->fresh());

        $this->assertSame('echec', $etape->issue);
        $this->assertFalse($etape->reessayable);
        $this->assertStringContainsString('rebranché', $this->cible->integration->fresh()->last_error);
        Http::assertNothingSent();
    }
}
