<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('twitch_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('twitch_id')->unique();
            $table->string('login');
            $table->string('display_name');
            $table->string('email')->nullable();
            $table->string('profile_image_url', 512)->nullable();
            $table->string('broadcaster_type')->nullable();
            // Tokens ficam criptografados com a APP_KEY (cast "encrypted" no model).
            $table->text('access_token');
            $table->text('refresh_token');
            $table->json('scopes');
            $table->timestamp('expires_at');
            $table->timestamp('last_validated_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('twitch_accounts');
    }
};
