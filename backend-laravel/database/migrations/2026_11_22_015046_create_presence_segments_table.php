<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Cada período em que a pessoa ficou no chat numa live: entrou em started_at, saiu em ended_at.
        // ended_at nulo = ainda está no chat (o fim provisório é o last_seen_at da estatística).
        Schema::create('presence_segments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('chatter_stream_stat_id')->constrained()->cascadeOnDelete();
            $table->timestamp('started_at');
            $table->timestamp('ended_at')->nullable();
            $table->timestamps();
            $table->index(['chatter_stream_stat_id', 'ended_at']);
        });

        // Dados que já existiam: um período só, da primeira à última vez vista (aproximação; o histórico
        // de entradas e saídas anteriores não foi registrado).
        $now = now();

        DB::table('chatter_stream_stats')
            ->join('stream_sessions', 'stream_sessions.id', '=', 'chatter_stream_stats.stream_session_id')
            ->whereNotNull('chatter_stream_stats.first_seen_at')
            ->select('chatter_stream_stats.id', 'chatter_stream_stats.first_seen_at', 'chatter_stream_stats.last_seen_at', 'stream_sessions.ended_at')
            ->orderBy('chatter_stream_stats.id')
            ->chunk(500, function ($rows) use ($now) {
                DB::table('presence_segments')->insert($rows->map(fn ($r) => [
                    'chatter_stream_stat_id' => $r->id,
                    'started_at' => $r->first_seen_at,
                    // Live encerrada ou pessoa sumida há mais de 1 minuto: período fechado.
                    'ended_at' => ($r->ended_at !== null || $r->last_seen_at < $now->copy()->subMinute()) ? $r->last_seen_at : null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ])->all());
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('presence_segments');
    }
};
