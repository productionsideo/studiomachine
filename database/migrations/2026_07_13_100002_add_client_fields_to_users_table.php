<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Rattache les utilisateurs à un client.
 * client_id NULL = membre de l'équipe Studio Machine (voit tous les clients).
 * client_id renseigné = contact chez le client (ne voit que ses données).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('client_id')->nullable()->after('id')
                ->constrained()->nullOnDelete();
            $table->string('role', 20)->default('client')->after('password');
            $table->boolean('active')->default(true)->after('role');
            $table->timestamp('last_login_at')->nullable()->after('active');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('client_id');
            $table->dropColumn(['role', 'active', 'last_login_at']);
        });
    }
};
