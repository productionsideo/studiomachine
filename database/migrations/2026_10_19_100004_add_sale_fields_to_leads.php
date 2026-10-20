<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Une demande peut aussi être une vente : la boutique d'un client (BJ21,
 * WooCommerce) envoie chaque paiement réussi. Le montant reste nul pour un
 * simple formulaire de contact. Une vente remboursée garde son montant, mais
 * sort du total des ventes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->string('produit', 255)->nullable()->after('message');
            $table->decimal('montant', 10, 2)->nullable()->after('produit');
            $table->string('devise', 3)->nullable()->after('montant');
            $table->timestamp('rembourse_at')->nullable()->after('devise');
        });
    }

    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->dropColumn(['produit', 'montant', 'devise', 'rembourse_at']);
        });
    }
};
