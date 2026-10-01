<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Où vit le fichier d'un média : sur le disque du serveur (« local », tous
 * les médias d'avant) ou chez Cloudflare R2 (« r2 », les nouveaux).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('media_assets', function (Blueprint $table) {
            $table->string('disk', 10)->default('local')->after('mime');
        });
    }

    public function down(): void
    {
        Schema::table('media_assets', function (Blueprint $table) {
            $table->dropColumn('disk');
        });
    }
};
