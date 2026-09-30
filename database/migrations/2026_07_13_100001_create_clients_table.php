<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Les clients de la plateforme (Construction CRD, et les suivants).
 * Chaque client possède une clé API que son site utilise pour pousser
 * ses événements et ses leads vers le dashboard.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clients', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();

            // La clé API n'est jamais stockée en clair : on garde son hash.
            // Le préfixe (8 premiers caractères) sert à retrouver la ligne
            // sans avoir à comparer le hash de chaque client à chaque requête.
            $table->string('api_key_prefix', 12)->unique();
            $table->string('api_key_hash');

            $table->string('website')->nullable();
            $table->string('accent_color', 7)->default('#9A0F20');
            $table->string('logo_path')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('clients');
    }
};
