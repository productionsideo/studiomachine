<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Les commentaires reçus sur les publications, et nos réponses.
 *
 * La table est alimentée par la synchronisation de chaque plateforme et sert
 * de boîte de réception unique. `reply_status` distingue la réponse envoyée
 * de la réponse en attente : si l'API de la plateforme refuse l'envoi (jeton
 * expiré, quota atteint), la réponse reste en file plutôt que d'être perdue.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('social_comments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->foreignId('capsule_post_id')->nullable()->constrained()->nullOnDelete();

            $table->string('platform', 20);
            $table->string('external_comment_id');
            $table->string('external_parent_id')->nullable();  // si c'est une réponse à un commentaire

            $table->string('author_name')->nullable();
            $table->string('author_external_id')->nullable();
            $table->text('text')->nullable();
            $table->timestamp('posted_at')->nullable();

            // Notre réponse
            $table->string('reply_status', 20)->default('aucune'); // aucune, en_attente, envoyee, echec
            $table->text('reply_text')->nullable();
            $table->foreignId('replied_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('replied_at')->nullable();
            $table->string('reply_error', 500)->nullable();
            $table->string('external_reply_id')->nullable();

            $table->boolean('hidden')->default(false);
            $table->timestamps();

            $table->unique(['platform', 'external_comment_id']);
            $table->index(['client_id', 'reply_status']);
            $table->index(['client_id', 'posted_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('social_comments');
    }
};
