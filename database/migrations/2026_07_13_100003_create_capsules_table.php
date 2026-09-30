<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Une capsule = un contenu vidéo (les 72 de CRD).
 * La même capsule est publiée sur plusieurs réseaux : c'est capsule_posts
 * qui porte chaque publication. Sans cette séparation, impossible de dire
 * si la capsule 12 convertit mieux sur TikTok ou sur Facebook.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('capsules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('number');       // 1 à 72
            $table->string('title')->nullable();
            $table->text('description')->nullable();
            $table->unsignedSmallInteger('duration_seconds')->nullable();
            $table->timestamps();

            $table->unique(['client_id', 'number']);
        });

        Schema::create('capsule_posts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('capsule_id')->constrained()->cascadeOnDelete();
            $table->string('platform', 20);              // tiktok, facebook, instagram, youtube
            $table->string('external_post_id')->nullable();
            $table->string('post_url', 500)->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();

            $table->unique(['capsule_id', 'platform']);
            $table->index(['platform', 'external_post_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('capsule_posts');
        Schema::dropIfExists('capsules');
    }
};
