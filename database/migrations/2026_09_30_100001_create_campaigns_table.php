<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Une campagne regroupe des publications autour d'un même objectif.
 *
 * Son `utm_campaign` est ce qui relie le social aux demandes reçues : chaque
 * lien publié dans la campagne le porte, et les sites clients le renvoient
 * déjà à l'ingestion (events.utm_campaign, leads.utm_campaign). On peut donc
 * dire combien de demandes une campagne a rapportées, pas seulement combien de
 * vues elle a faites.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('campaigns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->text('objective')->nullable();
            $table->string('color', 7)->default('#9A0F20');
            $table->string('utm_campaign', 100);
            $table->date('starts_on')->nullable();
            $table->date('ends_on')->nullable();
            $table->boolean('archived')->default(false);
            $table->timestamps();

            $table->unique(['client_id', 'utm_campaign']);
            $table->index(['client_id', 'archived']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('campaigns');
    }
};
