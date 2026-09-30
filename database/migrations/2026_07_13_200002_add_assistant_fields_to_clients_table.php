<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Le réglage de l'assistant, client par client.
 *
 * La plateforme est multi-clients : Claude ne peut pas répondre du même ton,
 * ni avec les mêmes faits, pour un constructeur de maisons et pour un
 * restaurant. Le contexte et la signature vivent donc sur le client, pas
 * dans le code.
 *
 * `ai_auto_reply` est FAUX par défaut, volontairement : personne ne se
 * retrouve avec un robot qui parle en son nom sans l'avoir demandé.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->boolean('ai_auto_reply')->default(false)->after('active');
            $table->text('ai_context')->nullable()->after('ai_auto_reply');
            $table->string('ai_signature', 120)->nullable()->after('ai_context');
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->dropColumn(['ai_auto_reply', 'ai_context', 'ai_signature']);
        });
    }
};
