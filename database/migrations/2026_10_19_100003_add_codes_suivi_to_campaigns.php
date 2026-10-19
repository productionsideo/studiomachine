<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Les autres codes de suivi d'une campagne. Une publicité Meta (ou Metricool)
 * met dans le lien l'identifiant de SA campagne publicitaire
 * (utm_campaign=120254359283750368), pas le code de la campagne /gestion :
 * on rattache ces codes à la campagne pour compter ses visites et demandes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('campaigns', function (Blueprint $table) {
            $table->json('codes_suivi')->nullable()->after('utm_campaign');
        });
    }

    public function down(): void
    {
        Schema::table('campaigns', function (Blueprint $table) {
            $table->dropColumn('codes_suivi');
        });
    }
};
