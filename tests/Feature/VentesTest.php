<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Lead;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * La boutique de BJ21 envoie chaque paiement réussi comme une demande avec
 * un montant : elle entre « gagnée », s'affiche comme un achat, et un renvoi
 * ne crée pas de doublon ni n'écrase le suivi.
 */
class VentesTest extends TestCase
{
    use RefreshDatabase;

    public function test_une_vente_woocommerce_arrive_dans_les_demandes(): void
    {
        $client = Client::create(['name' => 'BJ21', 'slug' => 'bj21rules', 'api_key_prefix' => 'provisoire00', 'api_key_hash' => '']);

        Artisan::call('clients:emettre-cle', ['slug' => 'bj21rules']);
        $cle = trim(Artisan::output());
        $this->assertStringStartsWith('sm_', $cle);

        $vente = [
            'external_id' => 'commande-1042', 'name' => 'Marie Tremblay', 'email' => 'marie@essai.test',
            'produit' => 'Livre numérique', 'montant' => '24.99', 'devise' => 'cad',
            'utm_source' => 'fb', 'utm_campaign' => 'bj21_lancement', 'platform' => 'facebook',
        ];

        $this->postJson('/api/v1/leads', $vente, ['X-API-Key' => $cle])->assertCreated();

        $lead = Lead::first();
        $this->assertSame('gagne', $lead->status);
        $this->assertSame('24.99', $lead->montant);
        $this->assertSame('CAD', $lead->devise);
        $this->assertSame('24,99 $', $lead->montant_label);

        // Renvoi (accusé de réception perdu) après un suivi fait à la main.
        $lead->update(['status' => 'perdu']);
        $this->postJson('/api/v1/leads', $vente, ['X-API-Key' => $cle])->assertOk();
        $this->assertSame(1, Lead::count());
        $this->assertSame('perdu', $lead->fresh()->status);

        $admin = User::create(['name' => 'Équipe', 'email' => 'a@essai.test', 'password' => 'x', 'role' => 'admin']);
        $this->actingAs($admin)->get(route('leads.index', ['client' => 'bj21rules']))
            ->assertOk()
            ->assertSee('1 vente pour')
            ->assertSee('Livre numérique')
            ->assertDontSee('Développement');

        $this->actingAs($admin)->get(route('leads.show', $lead))
            ->assertOk()->assertSee('L’achat', false)->assertSee('commande-1042');

        $this->actingAs($admin)->get(route('leads.export', ['client' => 'bj21rules']))
            ->assertOk()->assertDownload();
        $this->assertStringContainsString('24,99', $this->actingAs($admin)->get(route('leads.export', ['client' => 'bj21rules']))->streamedContent());
    }

    public function test_un_formulaire_sans_montant_reste_nouveau(): void
    {
        $client = Client::create(['name' => 'CRD', 'slug' => 'crd', 'api_key_prefix' => 'provisoire00', 'api_key_hash' => '']);
        $cle    = $client->generateApiKey();

        $this->postJson('/api/v1/leads', ['external_id' => 'f1', 'name' => 'A B', 'email' => 'a@b.test', 'project' => 'st-joachim'],
            ['X-API-Key' => $cle])->assertCreated();

        $this->assertSame('nouveau', Lead::first()->status);
        $this->assertNull(Lead::first()->montant_label);
    }
}
