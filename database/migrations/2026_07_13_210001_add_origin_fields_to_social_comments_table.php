<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * D'où vient le commentaire, indépendamment de nos capsules.
 *
 * `capsule_post_id` ne suffit pas : il n'est renseigné que si la publication
 * commentée correspond à l'une des 72 capsules connues. Or une Page reçoit
 * aussi des commentaires sur des publications qu'on n'a pas faites — une photo
 * de chantier mise en ligne par le client, une ancienne annonce.
 *
 * Sans ces deux colonnes, ces commentaires-là arriveraient orphelins : on
 * saurait qu'ils existent, sans pouvoir dire à quoi ils répondent. On les
 * garde donc rattachés à leur publication d'origine, capsule ou pas.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('social_comments', function (Blueprint $table) {
            $table->string('external_post_id')->nullable()->after('capsule_post_id');
            $table->string('post_permalink', 500)->nullable()->after('external_post_id');

            $table->index(['platform', 'external_post_id']);
        });
    }

    public function down(): void
    {
        Schema::table('social_comments', function (Blueprint $table) {
            $table->dropIndex(['platform', 'external_post_id']);
            $table->dropColumn(['external_post_id', 'post_permalink']);
        });
    }
};
