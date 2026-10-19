<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Deux ajouts pour l'import de campagne en CSV :
 *
 *   - `posts.cover_media_id` : la miniature (couverture) d'une publication
 *     vidéo, une image de la médiathèque. Chaque réseau l'utilise à sa façon
 *     (YouTube, Reels Instagram et Facebook, LinkedIn) ; TikTok n'en accepte pas.
 *
 *   - `post_fichiers_attendus` : un fichier nommé dans le CSV mais pas encore
 *     déposé. Dès qu'un média du même nom arrive dans la médiathèque du client,
 *     il est rattaché à la publication et la ligne disparaît. Tant qu'il en
 *     reste une, la publication ne peut pas être programmée.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('posts', function (Blueprint $table) {
            $table->foreignId('cover_media_id')->nullable()->after('link_url')
                ->constrained('media_assets')->nullOnDelete();
        });

        Schema::create('post_fichiers_attendus', function (Blueprint $table) {
            $table->id();
            $table->foreignId('post_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->string('nom');                    // tel qu'écrit dans le CSV
            $table->string('nom_normalise');          // minuscules, sans chemin : la clé de rapprochement
            $table->string('role', 20);               // media | miniature
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();

            $table->index(['client_id', 'nom_normalise']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('post_fichiers_attendus');

        Schema::table('posts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('cover_media_id');
        });
    }
};
