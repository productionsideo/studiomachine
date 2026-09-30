<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ce que Claude a compris d'un commentaire, et ce qu'il propose de répondre.
 *
 * L'analyse est conservée à côté du commentaire plutôt que recalculée à
 * l'affichage : elle coûte un appel d'API, et on veut pouvoir montrer
 * *pourquoi* une réponse est restée en brouillon, même des semaines plus tard.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('social_comments', function (Blueprint $table) {
            // anodin | sensible | negatif | pourriel
            $table->string('ai_category', 20)->nullable()->after('hidden');

            // Les sujets détectés : prix, delais, disponibilite…
            $table->json('ai_topics')->nullable()->after('ai_category');

            // haute | moyenne | faible — un doute suffit à exiger un humain.
            $table->string('ai_confidence', 10)->nullable()->after('ai_topics');

            $table->text('ai_draft')->nullable()->after('ai_confidence');

            // La justification, en une phrase. C'est ce qui rend la décision
            // lisible par la personne qui valide.
            $table->string('ai_note', 500)->nullable()->after('ai_draft');

            // Le code — pas le modèle — a jugé que ça pouvait partir seul.
            $table->boolean('ai_auto_ok')->default(false)->after('ai_note');

            $table->timestamp('ai_analyzed_at')->nullable()->after('ai_auto_ok');
            $table->string('ai_model', 40)->nullable()->after('ai_analyzed_at');
            $table->string('ai_error', 500)->nullable()->after('ai_model');

            // Distingue une réponse partie toute seule d'une réponse validée.
            // Sans ça, impossible de savoir a posteriori qui a parlé au client.
            $table->boolean('replied_by_ai')->default(false)->after('replied_by');

            $table->index(['client_id', 'ai_analyzed_at']);
        });
    }

    public function down(): void
    {
        Schema::table('social_comments', function (Blueprint $table) {
            $table->dropIndex(['client_id', 'ai_analyzed_at']);
            $table->dropColumn([
                'ai_category', 'ai_topics', 'ai_confidence', 'ai_draft',
                'ai_note', 'ai_auto_ok', 'ai_analyzed_at', 'ai_model',
                'ai_error', 'replied_by_ai',
            ]);
        });
    }
};
