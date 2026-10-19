<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Integration;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Chez Meta, chaque connexion remplace les autorisations précédentes : une
 * connexion qui coche toutes les Pages doit remettre en marche tous les
 * clients concernés, pas seulement celui d'où on l'a lancée.
 */
class ConnexionMetaTest extends TestCase
{
    use RefreshDatabase;

    public function test_une_connexion_remet_a_jour_les_jetons_des_autres_clients_de_la_meme_autorisation(): void
    {
        config(['services.meta.client_id' => 'app', 'services.meta.client_secret' => 's', 'meta.version' => 'v25.0', 'meta.login_config_id' => null]);

        $crd  = Client::create(['name' => 'CRD', 'slug' => 'crd', 'api_key_prefix' => 'sm_crd0000000', 'api_key_hash' => 'x']);
        $bj21 = Client::create(['name' => 'BJ21', 'slug' => 'bj21', 'api_key_prefix' => 'sm_bj21000000', 'api_key_hash' => 'x']);

        // BJ21 était branché, mais son jeton a été invalidé par une connexion précédente.
        Integration::create(['client_id' => $bj21->id, 'platform' => 'facebook', 'external_account_id' => 'PAGE_BJ21', 'access_token' => 'mort', 'active' => true, 'last_error' => 'code 190']);
        Integration::create(['client_id' => $bj21->id, 'platform' => 'instagram', 'external_account_id' => 'IG_BJ21', 'access_token' => 'mort', 'active' => true]);

        Http::fake([
            'graph.facebook.com/v25.0/oauth/access_token*' => Http::response(['access_token' => 'jeton-utilisateur']),
            'graph.facebook.com/v25.0/me/accounts*'        => Http::response(['data' => [
                ['id' => 'PAGE_CRD', 'name' => 'Construction CRD', 'access_token' => 'jeton-crd', 'instagram_business_account' => ['id' => 'IG_CRD', 'username' => 'constructioncrd']],
                ['id' => 'PAGE_BJ21', 'name' => 'Blackjack 21', 'access_token' => 'jeton-bj21', 'instagram_business_account' => ['id' => 'IG_BJ21', 'username' => 'blackjack21regles']],
            ]]),
            'graph.facebook.com/v25.0/me/businesses*'      => Http::response(['data' => []]),
        ]);

        $admin = User::create(['name' => 'Équipe', 'email' => 'a@essai.test', 'password' => 'x', 'role' => 'admin']);

        // Connexion lancée depuis CRD : deux Pages → choix de la Page.
        $this->actingAs($admin)->get(route('connexion.rediriger', [$crd, 'meta']));
        $etat = array_key_first(session('oauth'));
        $vue  = $this->actingAs($admin)->get(route('connexion.retour', ['fournisseur' => 'meta', 'state' => $etat, 'code' => 'c']))->assertOk();

        $this->actingAs($admin)->post(route('connexion.choisir'), ['jeton' => $vue->viewData('jeton'), 'groupe' => 'PAGE_CRD'])
            ->assertRedirect()
            ->assertSessionHas('ok', fn ($m) => str_contains($m, 'Aussi remis à jour : BJ21'));

        $this->assertSame('jeton-crd', Integration::where('client_id', $crd->id)->where('platform', 'facebook')->first()->access_token);
        $fbBj21 = Integration::where('client_id', $bj21->id)->where('platform', 'facebook')->first();
        $this->assertSame('jeton-bj21', $fbBj21->access_token);
        $this->assertNull($fbBj21->last_error);
        $this->assertSame('jeton-bj21', Integration::where('client_id', $bj21->id)->where('platform', 'instagram')->first()->access_token);
    }
}
