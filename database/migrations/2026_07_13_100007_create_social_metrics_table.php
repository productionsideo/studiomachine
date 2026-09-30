<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Instantanés des statistiques d'une publication (vues, écoute, engagement).
 *
 * On enregistre un instantané par collecte plutôt que d'écraser une seule
 * ligne : c'est ce qui permet de tracer la courbe d'une capsule dans le temps
 * et de voir quand une publication décolle. `collected_on` (date) borne la
 * granularité à une collecte par jour et par publication.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('social_metrics', function (Blueprint $table) {
            $table->id();
            $table->foreignId('capsule_post_id')->constrained()->cascadeOnDelete();

            $table->unsignedBigInteger('views')->default(0);
            $table->unsignedBigInteger('reach')->default(0);
            $table->unsignedBigInteger('likes')->default(0);
            $table->unsignedBigInteger('comments_count')->default(0);
            $table->unsignedBigInteger('shares')->default(0);
            $table->unsignedBigInteger('saves')->default(0);

            // Statistiques d'écoute
            $table->unsignedInteger('avg_watch_seconds')->nullable();
            $table->decimal('completion_rate', 5, 2)->nullable();  // % qui vont au bout

            $table->date('collected_on');
            $table->timestamps();

            $table->unique(['capsule_post_id', 'collected_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('social_metrics');
    }
};
