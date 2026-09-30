<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Qualification enrichie des demandes.
 *
 * `project` est la clé du développement visé (Construction CRD en a huit en
 * cours). C'est l'information la plus utile pour un représentant : elle dit
 * quel terrain, quelle maison modèle et quelle équipe de vente sont concernés.
 *
 * On stocke la clé (« beauport-louis-xiv ») et non le libellé : renommer un
 * projet sur le site ne réécrit alors pas l'historique des demandes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->string('project', 40)->nullable()->after('capsule_id');
            $table->string('home_type', 30)->nullable()->after('has_land');
            $table->string('bedrooms', 10)->nullable()->after('home_type');
            $table->string('financing', 20)->nullable()->after('budget');

            // Le dashboard compare les projets entre eux : sans index, ce
            // regroupement balaierait toute la table à chaque affichage.
            $table->index(['client_id', 'project']);
        });
    }

    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->dropIndex(['client_id', 'project']);
            $table->dropColumn(['project', 'home_type', 'bedrooms', 'financing']);
        });
    }
};
