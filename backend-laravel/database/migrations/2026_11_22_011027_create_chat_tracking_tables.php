<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Cada live (identificada pelo id de stream da Twitch).
        Schema::create('stream_sessions', function (Blueprint $table) {
            $table->id();
            $table->string('twitch_stream_id')->unique();
            $table->string('title')->nullable();
            $table->string('game_name')->nullable();
            $table->timestamp('started_at');
            $table->timestamp('ended_at')->nullable()->index();
            $table->timestamps();
        });

        // Pessoas que já apareceram no chat.
        Schema::create('chatters', function (Blueprint $table) {
            $table->id();
            $table->string('twitch_user_id')->unique();
            $table->string('login')->index();
            $table->string('display_name');
            $table->timestamps();
        });

        // Presença e contagem de mensagens de cada pessoa em cada live (só contagem, sem texto).
        Schema::create('chatter_stream_stats', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stream_session_id')->constrained()->cascadeOnDelete();
            $table->foreignId('chatter_id')->constrained()->cascadeOnDelete();
            $table->timestamp('first_seen_at')->nullable();
            $table->timestamp('last_seen_at')->nullable()->index();
            $table->unsignedInteger('watch_seconds')->default(0);
            $table->unsignedInteger('message_count')->default(0);
            $table->timestamp('last_message_at')->nullable();
            $table->timestamps();
            $table->unique(['stream_session_id', 'chatter_id']);
        });

        // Interações vindas do EventSub (resgates da loja de pontos etc.).
        Schema::create('channel_events', function (Blueprint $table) {
            $table->id();
            $table->string('twitch_event_id')->unique();
            $table->string('type')->index();
            $table->foreignId('stream_session_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('chatter_id')->nullable()->constrained()->nullOnDelete();
            $table->string('user_login')->nullable();
            $table->string('user_name')->nullable();
            $table->string('title')->nullable();
            $table->unsignedInteger('cost')->nullable();
            $table->text('user_input')->nullable();
            $table->string('status')->nullable();
            $table->timestamp('occurred_at')->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('channel_events');
        Schema::dropIfExists('chatter_stream_stats');
        Schema::dropIfExists('chatters');
        Schema::dropIfExists('stream_sessions');
    }
};
