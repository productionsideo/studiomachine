<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Une publication et ses déclinaisons par réseau.
 *
 * `posts` porte ce qui est commun (texte, médias, date) ; `post_targets` porte
 * ce qui diffère d'un réseau à l'autre (format, texte adapté, réglages) et,
 * surtout, l'ÉTAT de chaque envoi. Une publication peut très bien partir sur
 * Facebook et échouer sur TikTok : chaque cible vit sa vie, et l'échec de
 * l'une ne doit jamais faire republier les autres.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('posts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->foreignId('campaign_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->string('title')->nullable();        // libellé interne, jamais publié
            $table->text('caption')->nullable();
            $table->string('link_url', 500)->nullable();

            // UTC en base ; affiché dans le fuseau de config('publication.fuseau').
            $table->timestamp('scheduled_at')->nullable();

            // brouillon, programmee, en_cours, publiee, partielle, echec
            $table->string('status', 20)->default('brouillon');

            $table->timestamps();

            $table->index(['client_id', 'scheduled_at']);
            $table->index(['status', 'scheduled_at']);
        });

        Schema::create('post_media', function (Blueprint $table) {
            $table->foreignId('post_id')->constrained()->cascadeOnDelete();
            $table->foreignId('media_asset_id')->constrained()->restrictOnDelete();
            $table->unsignedTinyInteger('position')->default(0);

            $table->primary(['post_id', 'media_asset_id']);
        });

        Schema::create('post_targets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('post_id')->constrained()->cascadeOnDelete();
            $table->foreignId('integration_id')->nullable()->constrained()->nullOnDelete();
            $table->string('platform', 20);

            // facebook : post, reel · instagram : reel, image, carrousel, story
            // youtube : short, video · tiktok : video
            $table->string('format', 20);
            $table->text('caption_override')->nullable();
            $table->json('options')->nullable();

            // en_attente, en_cours, publiee, echec, annulee
            $table->string('status', 20)->default('en_attente');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('next_attempt_at')->nullable();
            $table->timestamp('started_at')->nullable();

            // Identifiant intermédiaire (conteneur Instagram, envoi TikTok…)
            // pendant que la plateforme traite la vidéo.
            $table->string('external_job_id')->nullable();
            $table->string('external_post_id')->nullable();
            $table->string('permalink', 500)->nullable();
            $table->timestamp('published_at')->nullable();
            $table->string('last_error', 1000)->nullable();

            $table->timestamps();

            $table->unique(['post_id', 'platform']);
            $table->index(['status', 'next_attempt_at']);
            $table->index(['platform', 'external_post_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('post_targets');
        Schema::dropIfExists('post_media');
        Schema::dropIfExists('posts');
    }
};
