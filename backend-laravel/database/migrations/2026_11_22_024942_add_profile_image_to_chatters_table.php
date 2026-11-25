<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chatters', function (Blueprint $table) {
            // Foto de perfil pública da Twitch (buscada em lote pelo /helix/users).
            $table->string('profile_image_url', 512)->nullable()->after('display_name');
            $table->timestamp('profile_fetched_at')->nullable()->after('profile_image_url');
        });
    }

    public function down(): void
    {
        Schema::table('chatters', function (Blueprint $table) {
            $table->dropColumn(['profile_image_url', 'profile_fetched_at']);
        });
    }
};
