<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Les statistiques des comptes connectés, collectées par statistiques:collecter.
 *
 *   - `stats_comptes` : une ligne par compte et par jour. Une valeur absente
 *     (null) veut dire « le réseau ne la fournit pas », pas zéro.
 *   - `stats_publications` : chaque publication du compte, faite ou non par
 *     /gestion, avec ses derniers chiffres (cumulés depuis sa sortie).
 *     `post_target_id` la relie à notre cible quand c'est nous qui l'avons publiée.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stats_comptes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('integration_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->string('platform', 20);
            $table->date('jour');

            $table->unsignedBigInteger('abonnes')->nullable();       // total à la fin du jour
            $table->unsignedBigInteger('vues')->nullable();          // vues/affichages du jour
            $table->unsignedBigInteger('portee')->nullable();        // comptes uniques touchés ce jour
            $table->unsignedBigInteger('interactions')->nullable();  // réactions, commentaires, partages… du jour
            $table->unsignedBigInteger('vues_cumulees')->nullable(); // YouTube : total de la chaîne (sert à calculer les vues du jour)

            $table->timestamps();
            $table->unique(['integration_id', 'jour']);
            $table->index(['client_id', 'jour']);
        });

        Schema::create('stats_publications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('integration_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->foreignId('post_target_id')->nullable()->constrained()->nullOnDelete();
            $table->string('platform', 20);
            $table->string('external_id');
            $table->string('type', 20);                      // video, image, carrousel, texte, reel, short, story
            $table->timestamp('publie_le');
            $table->text('texte')->nullable();
            $table->string('vignette', 1000)->nullable();
            $table->string('lien', 1000)->nullable();

            $table->unsignedBigInteger('vues')->nullable();
            $table->unsignedBigInteger('portee')->nullable();
            $table->unsignedBigInteger('interactions')->nullable();
            $table->unsignedBigInteger('j_aime')->nullable();
            $table->unsignedBigInteger('commentaires')->nullable();
            $table->unsignedBigInteger('partages')->nullable();
            $table->unsignedBigInteger('enregistrements')->nullable();

            $table->timestamp('collecte_le');
            $table->timestamps();
            $table->unique(['integration_id', 'external_id']);
            $table->index(['client_id', 'publie_le']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stats_publications');
        Schema::dropIfExists('stats_comptes');
    }
};
