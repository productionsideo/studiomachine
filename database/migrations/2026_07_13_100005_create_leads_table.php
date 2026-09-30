<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Les demandes complétées sur maison-neuve.constructioncrd.com.
 *
 * Contient des renseignements personnels (Loi 25) : l'accès est restreint
 * au client propriétaire et à l'équipe interne. `external_id` est l'identifiant
 * généré par le site client — il rend l'ingestion idempotente, pour qu'un
 * renvoi après une panne réseau ne crée pas un doublon.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->foreignId('capsule_id')->nullable()->constrained()->nullOnDelete();

            $table->string('external_id', 64);
            $table->string('platform', 20)->nullable();
            $table->string('session_id', 64)->nullable();

            // Coordonnées
            $table->string('name');
            $table->string('email');
            $table->string('phone', 30)->nullable();

            // Qualification
            $table->string('has_land', 30)->nullable();    // oui, non, en recherche
            $table->string('sector', 120)->nullable();     // secteur souhaité
            $table->string('budget', 40)->nullable();
            $table->string('timeline', 40)->nullable();    // échéancier
            $table->text('message')->nullable();

            // Provenance
            $table->string('utm_source', 100)->nullable();
            $table->string('utm_medium', 100)->nullable();
            $table->string('utm_campaign', 100)->nullable();
            $table->string('utm_content', 100)->nullable();
            $table->string('utm_term', 100)->nullable();
            $table->string('device', 20)->nullable();
            $table->string('referrer', 500)->nullable();
            $table->string('ip_hash', 64)->nullable();
            $table->string('locale', 5)->default('fr');

            // Suivi commercial
            $table->string('status', 20)->default('nouveau'); // nouveau, contacte, qualifie, gagne, perdu
            $table->text('notes')->nullable();

            $table->timestamp('submitted_at')->nullable();
            $table->timestamps();

            $table->unique(['client_id', 'external_id']);
            $table->index(['client_id', 'status']);
            $table->index(['client_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leads');
    }
};
