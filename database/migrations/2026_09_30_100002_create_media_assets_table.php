<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * La médiathèque : images et vidéos d'un client, prêtes à être publiées.
 *
 * Le fichier vit sur disque, jamais en base. `filename` est aléatoire : c'est
 * lui qui rend l'URL publique impossible à deviner (Instagram et TikTok vont
 * chercher la vidéo eux-mêmes, elle doit donc être accessible sans session).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('media_assets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();

            $table->string('kind', 10);                 // image, video
            $table->string('original_name');
            $table->string('filename', 80)->unique();
            $table->string('thumbnail', 80)->nullable();
            $table->string('mime', 60);
            $table->unsignedBigInteger('size_bytes');

            $table->unsignedSmallInteger('width')->nullable();
            $table->unsignedSmallInteger('height')->nullable();
            $table->decimal('duration_seconds', 8, 2)->nullable();

            $table->timestamps();

            $table->index(['client_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('media_assets');
    }
};
