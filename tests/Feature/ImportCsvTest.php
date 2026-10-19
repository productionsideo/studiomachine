<?php

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\Client;
use App\Models\FichierAttendu;
use App\Models\Integration;
use App\Models\MediaAsset;
use App\Models\Post;
use App\Models\User;
use App\Services\Publication\ImportCsv;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * Import CSV : une ligne par publication et par réseau, regroupées par
 * moment ; les fichiers nommés se rattachent seuls à leur dépôt.
 */
class ImportCsvTest extends TestCase
{
    use RefreshDatabase;

    private Client $client;
    private Campaign $campagne;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = Client::create(['name' => 'BJ21', 'slug' => 'bj21', 'api_key_prefix' => 'sm_bj21000000', 'api_key_hash' => 'x']);
        foreach (['facebook', 'instagram', 'youtube', 'linkedin'] as $p) {
            Integration::create(['client_id' => $this->client->id, 'platform' => $p, 'access_token' => 'j', 'active' => true]);
        }
        $this->campagne = $this->client->campaigns()->create(['name' => 'Novembre', 'utm_campaign' => 'novembre']);
        $this->admin    = User::create(['name' => 'Équipe', 'email' => 'a@essai.test', 'password' => 'x', 'role' => 'admin']);
    }

    private function import(): ImportCsv
    {
        return app(ImportCsv::class);
    }

    private function media(string $nom, string $kind = 'video'): MediaAsset
    {
        return MediaAsset::create([
            'client_id' => $this->client->id, 'kind' => $kind, 'mime' => $kind === 'video' ? 'video/mp4' : 'image/jpeg',
            'disk' => 'local', 'filename' => bin2hex(random_bytes(10)) . ($kind === 'video' ? '.mp4' : '.jpg'),
            'original_name' => $nom, 'size_bytes' => 1000, 'width' => 1080, 'height' => 1920,
        ]);
    }

    public function test_le_gabarit_se_relit_sans_erreur_et_regroupe_par_moment(): void
    {
        $a = $this->import()->analyser($this->client, $this->import()->gabarit());

        $this->assertSame([], $a['erreurs']);
        // Facebook + Instagram à 12 h = une publication ; YouTube à 17 h 30 et LinkedIn le lendemain à part.
        $this->assertCount(3, $a['publications']);
        $this->assertSame(['facebook', 'instagram'], array_keys($a['publications'][0]['cibles']));
        $this->assertFalse($a['publications'][0]['fichiers']['regle01.mp4']);
    }

    public function test_l_import_compose_les_textes_et_attend_les_fichiers_qui_se_rattachent_seuls(): void
    {
        $a = $this->import()->analyser($this->client, $this->import()->gabarit());
        $bilan = $this->import()->importer($this->client, $this->campagne, $a['publications'], $this->admin);

        $this->assertSame(3, $bilan['creees']);

        $post = Post::where('title', 'Règle 1 — le hasard')->first();
        $this->assertSame('brouillon', $post->status);

        $ig = $post->targets()->where('platform', 'instagram')->first();
        $this->assertStringContainsString('@partenaire', $ig->caption_override);
        $this->assertStringContainsString('#casino', $ig->caption_override);
        $this->assertSame(['partenaire'], $ig->options['collaborateurs']);

        $yt = Post::where('title', 'Règle 1 — le hasard au blackjack')->first()->targets()->first();
        $this->assertSame('Règle 1 — le hasard au blackjack', $yt->options['titre']);
        $this->assertTrue($yt->options['ia']);

        // Rien n'est encore déposé : deux publications attendent regle01.mp4.
        $this->assertSame(2, FichierAttendu::where('nom_normalise', 'regle01.mp4')->count());

        // Le dépôt (nom en majuscules : peu importe) rattache la vidéo partout.
        $video = $this->media('REGLE01.MP4');
        $this->assertTrue($post->fresh()->media->contains($video));
        $this->assertSame(0, FichierAttendu::where('nom_normalise', 'regle01.mp4')->count());

        // La miniature arrive ensuite et devient la couverture.
        $image = $this->media('regle01-couverture.jpg', 'image');
        $this->assertSame($image->id, $post->fresh()->cover_media_id);
    }

    public function test_un_fichier_deja_depose_est_pris_tout_de_suite(): void
    {
        $video = $this->media('regle01.mp4');

        $a = $this->import()->analyser($this->client, $this->import()->gabarit());
        $this->assertTrue($a['publications'][0]['fichiers']['regle01.mp4']);

        $this->import()->importer($this->client, $this->campagne, $a['publications'], $this->admin);

        $this->assertTrue(Post::where('title', 'Règle 1 — le hasard')->first()->media->contains($video));
    }

    public function test_excel_en_francais_point_virgule_et_dates_a_la_francaise(): void
    {
        $csv = "date;heure;reseau;format;titre;texte;hashtags\n03/11/2026;12h00;Facebook;Publication;Test;Bonjour;bj21, casino\n";

        $a = $this->import()->analyser($this->client, $csv);

        $this->assertSame([], $a['erreurs']);
        $p = $a['publications'][0];
        $this->assertSame('2026-11-03 12:00', $p['quand']->format('Y-m-d H:i'));
        $this->assertSame('post', $p['cibles']['facebook']['format']);
        $this->assertStringEndsWith("#bj21 #casino", $p['cibles']['facebook']['texte']);
    }

    public function test_les_erreurs_sont_signalees_et_rien_n_est_importe(): void
    {
        $csv = "date,heure,reseau,titre\n2026-11-03,12:00,myspace,A\n2026-11-03,12:00,facebook,B\n2026-11-03,12:00,facebook,B\n2026-13-45,12:00,facebook,C\n";

        $a = $this->import()->analyser($this->client, $csv);

        $this->assertCount(3, $a['erreurs']);
        $this->assertStringContainsString('réseau inconnu', $a['erreurs'][0]);
        $this->assertStringContainsString('deux fois', $a['erreurs'][1]);
        $this->assertStringContainsString('illisible', $a['erreurs'][2]);
    }

    public function test_reimporter_met_a_jour_au_lieu_de_doubler(): void
    {
        $a = $this->import()->analyser($this->client, $this->import()->gabarit());
        $this->import()->importer($this->client, $this->campagne, $a['publications'], $this->admin);
        $bilan = $this->import()->importer($this->client, $this->campagne, $a['publications'], $this->admin);

        $this->assertSame(['creees' => 0, 'mises_a_jour' => 3, 'ignorees' => 0], $bilan);
        $this->assertSame(3, $this->campagne->posts()->count());
    }

    public function test_le_parcours_complet_par_l_ecran_et_le_refus_de_programmer_sans_fichier(): void
    {
        $fichier = UploadedFile::fake()->createWithContent('plan.csv', $this->import()->gabarit());

        $vue = $this->actingAs($this->admin)
            ->post(route('campaigns.import.apercu', $this->campagne), ['fichier' => $fichier])
            ->assertOk()
            ->assertSee('3 publications à créer')
            ->assertSee('regle01.mp4');

        $jeton = $vue->viewData('jeton');
        $this->actingAs($this->admin)
            ->post(route('campaigns.import.confirmer', $this->campagne), ['jeton' => $jeton])
            ->assertRedirect(route('campaigns.show', $this->campagne));

        // La vidéo manque : programmer est refusé, avec le nom du fichier attendu.
        $post = Post::where('title', 'Règle 1 — le hasard')->first();
        $this->actingAs($this->admin)
            ->patch(route('posts.update', $post), [
                'action' => 'programmer', 'jour' => now()->addDays(3)->format('Y-m-d'), 'heure' => '12:00',
                'reseaux' => ['facebook' => ['actif' => 1, 'format' => 'reel']],
            ])
            ->assertSessionHasErrors('reseaux');

        $this->assertSame('brouillon', $post->fresh()->status);
    }

    public function test_instagram_recoit_la_couverture_et_les_collaborateurs(): void
    {
        \Illuminate\Support\Facades\Http::fake(['graph.facebook.com/*' => \Illuminate\Support\Facades\Http::response(['id' => 'C1'])]);

        $video = $this->media('regle01.mp4');
        $image = $this->media('couv.jpg', 'image');
        $post  = Post::create(['client_id' => $this->client->id, 'status' => 'programmee', 'scheduled_at' => now(), 'caption' => 'Bonjour', 'cover_media_id' => $image->id]);
        $post->media()->attach($video->id, ['position' => 0]);
        $ig = Integration::where('platform', 'instagram')->first();
        $ig->update(['settings' => ['ig_user_id' => '17841']]);
        $cible = $post->targets()->create(['integration_id' => $ig->id, 'platform' => 'instagram', 'format' => 'reel', 'options' => ['collaborateurs' => ['partenaire', 'ami']]]);

        $etape = app(\App\Services\Publication\PublieurInstagram::class)->demarrer($cible->fresh());

        $this->assertSame('en_cours', $etape->issue);
        \Illuminate\Support\Facades\Http::assertSent(fn ($r) => str_contains($r->url(), '/17841/media')
            && $r['cover_url'] === $image->url()
            && $r['collaborators'] === '["partenaire","ami"]');
    }

    public function test_l_ecran_d_import_et_le_gabarit_s_ouvrent(): void
    {
        $this->actingAs($this->admin)->get(route('campaigns.import', $this->campagne))->assertOk()->assertSee('Les colonnes');
        $this->actingAs($this->admin)->get(route('campaigns.gabarit'))->assertOk()->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
        $this->actingAs($this->admin)->get(route('campaigns.show', $this->campagne))->assertSee('Importer un CSV');
    }
}
