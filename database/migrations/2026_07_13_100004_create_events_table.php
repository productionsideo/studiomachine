<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Le parcours du visiteur, poussé par le site client via l'API d'ingestion.
 *
 * Types d'événements :
 *   pageview     — arrivée sur la page (c'est le « clic » depuis la capsule)
 *   form_start   — le visiteur commence à remplir
 *   step         — il franchit une étape (le champ `step` dit laquelle)
 *   submit       — formulaire complété
 *   abandon      — il quitte sans terminer (le champ `step` dit où il a lâché)
 *
 * Aucune adresse IP en clair : on ne garde qu'un hash salé (Loi 25).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->foreignId('capsule_id')->nullable()->constrained()->nullOnDelete();

            $table->string('platform', 20)->nullable();   // réseau d'origine du clic
            $table->string('session_id', 64)->index();
            $table->string('type', 20);
            $table->unsignedTinyInteger('step')->nullable();

            $table->string('page', 500)->nullable();
            $table->string('device', 20)->nullable();     // mobile, tablette, ordinateur
            $table->string('os', 40)->nullable();
            $table->string('browser', 40)->nullable();
            $table->string('referrer', 500)->nullable();
            $table->string('country', 2)->nullable();

            $table->string('utm_source', 100)->nullable();
            $table->string('utm_medium', 100)->nullable();
            $table->string('utm_campaign', 100)->nullable();
            $table->string('utm_content', 100)->nullable();
            $table->string('utm_term', 100)->nullable();

            $table->string('ip_hash', 64)->nullable();
            $table->json('meta')->nullable();
            $table->timestamp('created_at')->useCurrent();

            // Les requêtes du dashboard filtrent toujours par client + période,
            // et classent les capsules par type d'événement.
            $table->index(['client_id', 'created_at']);
            $table->index(['client_id', 'capsule_id', 'type']);
            $table->index(['client_id', 'type', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('events');
    }
};
