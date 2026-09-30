<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Les comptes tiers connectés par client : Google Analytics (GA4), YouTube,
 * Facebook, Instagram, TikTok.
 *
 * Les jetons sont chiffrés au repos par Eloquent (cast 'encrypted' sur le
 * modèle) — la clé vit dans APP_KEY, hors base. Un vol de dump SQL ne donne
 * donc pas accès aux comptes sociaux des clients.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('integrations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();

            $table->string('platform', 20);   // ga4, youtube, facebook, instagram, tiktok
            $table->string('external_account_id')->nullable();
            $table->string('account_name')->nullable();

            $table->text('access_token')->nullable();
            $table->text('refresh_token')->nullable();
            $table->timestamp('token_expires_at')->nullable();
            $table->text('scopes')->nullable();

            // Réglages propres à la plateforme (ex : property_id pour GA4,
            // page_id pour Facebook, channel_id pour YouTube).
            $table->json('settings')->nullable();

            $table->boolean('active')->default(true);
            $table->timestamp('last_synced_at')->nullable();
            $table->string('last_error', 500)->nullable();
            $table->timestamps();

            $table->unique(['client_id', 'platform']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('integrations');
    }
};
