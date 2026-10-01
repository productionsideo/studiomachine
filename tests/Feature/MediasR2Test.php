<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\MediaAsset;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Médias sur Cloudflare R2 : la vidéo va du navigateur à R2 sans passer par
 * le serveur ; le serveur ouvre l'envoi, l'assemble et vérifie le résultat.
 */
class MediasR2Test extends TestCase
{
    use RefreshDatabase;

    private const MP4 = "\x00\x00\x00\x20ftypisom\x00\x00\x02\x00isomiso2avc1mp41\x00\x00\x00\x08free";

    private Client $client;
    private User $admin;
    private string $dossier;

    /** @var array<int, string> méthode + chemin + requête de chaque appel à R2 */
    private array $appels = [];
    private int $tailleStockee = 50_000_000;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dossier = sys_get_temp_dir() . '/medias-r2-' . bin2hex(random_bytes(4));
        config([
            'publication.medias.dossier'    => "{$this->dossier}/medias",
            'publication.medias.temporaire' => "{$this->dossier}/envois",
            'publication.medias.cache'      => "{$this->dossier}/cache",
            'publication.medias.r2' => [
                'compte' => 'compte123', 'cle' => 'cle', 'secret' => 'secret', 'bucket' => 'studiomachine',
                'url' => 'https://medias.studiomachine.ca', 'part_octets' => 10 * 1024 * 1024,
            ],
        ]);

        $this->client = Client::create([
            'name' => 'Essai', 'slug' => 'essai', 'api_key_prefix' => 'sm_essai0000', 'api_key_hash' => 'x',
        ]);
        $this->admin = User::create([
            'name' => 'Équipe', 'email' => 'equipe@example.com', 'password' => 'x', 'role' => User::ROLE_ADMIN,
        ]);

        Http::fake(['compte123.r2.cloudflarestorage.com/*' => function (Request $r) {
            $url = parse_url($r->url());

            // Un en-tête signé envoyé en double (« a, a ») casse la signature.
            foreach ($r->headers() as $nom => $valeurs) {
                $this->assertCount(1, $valeurs, "En-tête {$nom} envoyé plusieurs fois à R2");
            }

            $this->appels[] = $r->method() . ' ' . $url['path'] . (isset($url['query']) ? '?' . $url['query'] : '');

            return match (true) {
                $r->method() === 'POST' && str_contains($r->url(), '?uploads') =>
                    Http::response('<InitiateMultipartUploadResult><UploadId>envoi-42</UploadId></InitiateMultipartUploadResult>'),
                $r->method() === 'POST' => Http::response('<CompleteMultipartUploadResult/>'),
                $r->method() === 'HEAD' => Http::response('', 200, ['Content-Length' => (string) $this->tailleStockee]),
                $r->method() === 'GET'  => Http::response(self::MP4, 206),
                default                 => Http::response('', 200),
            };
        }]);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->dossier));
        parent::tearDown();
    }

    public function test_une_video_va_directement_sur_r2(): void
    {
        $debut = $this->actingAs($this->admin)->postJson(route('medias.direct.debut', $this->client), [
            'nom' => 'Satellite 9x16.mp4', 'taille' => 50_000_000, 'type' => 'video/mp4',
        ])->assertOk()->json();

        $this->assertSame('envoi-42', $debut['envoi']);
        $this->assertCount(5, $debut['urls']);   // 50 Mo en parties de 10 Mio
        $this->assertStringContainsString('partNumber=5', $debut['urls'][4]);
        $this->assertStringContainsString('X-Amz-Signature=', $debut['urls'][0]);

        $media = $this->actingAs($this->admin)->post(route('medias.direct.fin', $this->client), [
            'envoi'    => 'envoi-42',
            'parts'    => [1 => '"a"', 2 => '"b"', 3 => '"c"', 4 => '"d"', 5 => '"e"'],
            'largeur'  => 1080, 'hauteur' => 1920, 'duree' => 17.578,
            'vignette' => UploadedFile::fake()->image('vignette.jpg', 480, 854),
        ], ['Accept' => 'application/json'])->assertOk()->json('media');

        $m = MediaAsset::findOrFail($media['id']);
        $this->assertSame('r2', $m->disk);
        $this->assertSame('video/mp4', $m->mime);
        $this->assertSame([1080, 1920, 17.58], [$m->width, $m->height, $m->duration_seconds]);
        $this->assertStringStartsWith('https://medias.studiomachine.ca/', $m->url());
        $this->assertStringStartsWith('https://medias.studiomachine.ca/', $m->vignetteUrl());
        $this->assertTrue($m->estVertical());

        // Assemblage, vérification, puis dépôt de la vignette.
        $this->assertContains("POST /studiomachine/{$m->filename}?uploadId=envoi-42", $this->appels);
        $this->assertContains("PUT /studiomachine/{$m->thumbnail}", $this->appels);
    }

    public function test_un_fichier_incomplet_est_refuse_et_efface(): void
    {
        $this->actingAs($this->admin)->postJson(route('medias.direct.debut', $this->client), [
            'nom' => 'v.mp4', 'taille' => 50_000_000, 'type' => 'video/mp4',
        ])->assertOk();

        $this->tailleStockee = 12_345;

        $this->actingAs($this->admin)->postJson(route('medias.direct.fin', $this->client), [
            'envoi' => 'envoi-42', 'parts' => [1 => 'a'],
        ])->assertStatus(422)->assertJsonPath('erreur', 'Le fichier reçu est incomplet. Recommencez l’envoi.');

        $this->assertSame(0, MediaAsset::count());
        $this->assertNotEmpty(array_filter($this->appels, fn ($a) => str_starts_with($a, 'DELETE /studiomachine/')));
    }

    public function test_un_envoi_d_un_autre_client_est_refuse(): void
    {
        $this->actingAs($this->admin)->postJson(route('medias.direct.debut', $this->client), [
            'nom' => 'v.mp4', 'taille' => 1000, 'type' => 'video/mp4',
        ])->assertOk();

        $autre = Client::create(['name' => 'Autre', 'slug' => 'autre', 'api_key_prefix' => 'sm_autre00000', 'api_key_hash' => 'x']);

        $this->actingAs($this->admin)->postJson(route('medias.direct.fin', $autre), [
            'envoi' => 'envoi-42', 'parts' => [1 => 'a'],
        ])->assertStatus(422)->assertJsonPath('erreur', 'Envoi inconnu ou expiré. Recommencez.');
    }

    public function test_une_image_passe_par_le_serveur_puis_rejoint_r2(): void
    {
        $image = UploadedFile::fake()->image('affiche.jpg', 1080, 1350);

        $media = $this->actingAs($this->admin)->post(route('medias.morceau', $this->client), [
            'envoi' => str_repeat('a', 32), 'index' => 0, 'total' => 1, 'nom' => 'affiche.jpg', 'morceau' => $image,
        ], ['Accept' => 'application/json'])->assertOk()->json('media');

        $m = MediaAsset::findOrFail($media['id']);
        $this->assertSame('r2', $m->disk);
        $this->assertSame([1080, 1350], [$m->width, $m->height]);
        $this->assertContains("PUT /studiomachine/{$m->filename}", $this->appels);
        $this->assertFileDoesNotExist(config('publication.medias.dossier') . '/' . $m->filename);
    }

    public function test_supprimer_un_media_r2_efface_l_objet(): void
    {
        $m = MediaAsset::create([
            'client_id' => $this->client->id, 'kind' => 'video', 'original_name' => 'v.mp4',
            'filename' => 'abc.mp4', 'thumbnail' => 'abc.jpg', 'mime' => 'video/mp4', 'disk' => 'r2', 'size_bytes' => 10,
        ]);

        $this->actingAs($this->admin)->delete(route('medias.destroy', $m))->assertRedirect();

        $this->assertNull(MediaAsset::find($m->id));
        $this->assertContains('DELETE /studiomachine/abc.mp4', $this->appels);
        $this->assertContains('DELETE /studiomachine/abc.jpg', $this->appels);
    }
}
