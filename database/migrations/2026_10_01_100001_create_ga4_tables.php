<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Copie locale des chiffres Google Analytics 4, jour par jour.
 *
 * On ne questionne pas Google à chaque affichage : la Data API compte ses
 * « jetons » par propriété et par heure, et une page de tableau de bord
 * rechargée dix fois ne doit pas épuiser le quota. Une collecte planifiée
 * remplit ces tables ; les pages ne lisent que la base.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Le trafic, par jour et par provenance (source / support / campagne).
        Schema::create('ga4_trafic', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->date('jour');
            $table->string('source', 150);
            $table->string('support', 150);       // sessionMedium
            $table->string('campagne', 150);      // sessionCampaignName

            $table->unsignedInteger('sessions')->default(0);
            $table->unsignedInteger('utilisateurs')->default(0);
            $table->unsignedInteger('sessions_engagees')->default(0);
            $table->unsignedInteger('evenements_cles')->default(0);
            $table->unsignedInteger('achats')->default(0);
            $table->decimal('revenus', 12, 2)->default(0);

            $table->timestamps();

            $table->unique(['client_id', 'jour', 'source', 'support', 'campagne'], 'ga4_trafic_unique');
            $table->index(['client_id', 'campagne', 'jour']);
        });

        // Quelques événements précis, comptés par campagne : les clics vers
        // Amazon surtout, qu'aucun autre suivi ne voit.
        Schema::create('ga4_evenements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->date('jour');
            $table->string('evenement', 60);
            $table->string('campagne', 150);
            $table->string('source', 150);
            $table->unsignedInteger('nombre')->default(0);
            $table->timestamps();

            $table->unique(['client_id', 'jour', 'evenement', 'campagne', 'source'], 'ga4_evenements_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ga4_evenements');
        Schema::dropIfExists('ga4_trafic');
    }
};
