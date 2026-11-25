<?php

use App\Services\Economy\SettingsDefaults;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Valores ajustáveis da economia (sem números fixos no código).
        Schema::create('settings', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->text('value');
            $table->string('description')->nullable();
            $table->timestamps();
        });

        // Livro-caixa: toda moeda e todo XP passam por aqui. O saldo em chatters é só um cache disto.
        Schema::create('transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('chatter_id')->constrained()->cascadeOnDelete();
            $table->foreignId('stream_session_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type')->index();
            $table->integer('coins_delta')->default(0);
            $table->integer('xp_delta')->default(0);
            $table->json('meta')->nullable();
            $table->timestamp('created_at')->useCurrent()->index();
            $table->index(['chatter_id', 'created_at']);
        });

        Schema::table('chatters', function (Blueprint $table) {
            $table->bigInteger('coins')->default(0)->after('profile_fetched_at');
            $table->unsignedBigInteger('xp')->default(0)->index()->after('coins');
            $table->unsignedInteger('level')->default(1)->after('xp');
            // 0 = não é sub; 1..3 = tier (Prime conta como 1).
            $table->unsignedTinyInteger('sub_tier')->default(0)->after('level');
            $table->boolean('sub_is_prime')->default(false)->after('sub_tier');
            $table->unsignedInteger('current_streak')->default(0)->after('sub_is_prime');
            $table->unsignedInteger('best_streak')->default(0)->after('current_streak');
            $table->foreignId('last_counted_session_id')->nullable()->after('best_streak')
                ->constrained('stream_sessions')->nullOnDelete();
        });

        Schema::table('chatter_stream_stats', function (Blueprint $table) {
            // Segundos de presença que já viraram XP (o resto é pago na próxima rodada).
            $table->unsignedInteger('xp_awarded_seconds')->default(0)->after('watch_seconds');
            $table->boolean('counted_for_streak')->default(false)->after('xp_awarded_seconds');
        });

        $now = now();
        DB::table('settings')->insertOrIgnore(collect(SettingsDefaults::all())->map(fn ($v, $k) => [
            'key' => $k,
            'value' => $v[0],
            'description' => $v[1],
            'created_at' => $now,
            'updated_at' => $now,
        ])->values()->all());
    }

    public function down(): void
    {
        Schema::table('chatter_stream_stats', function (Blueprint $table) {
            $table->dropColumn(['xp_awarded_seconds', 'counted_for_streak']);
        });

        Schema::table('chatters', function (Blueprint $table) {
            $table->dropConstrainedForeignId('last_counted_session_id');
            $table->dropColumn(['coins', 'xp', 'level', 'sub_tier', 'sub_is_prime', 'current_streak', 'best_streak']);
        });

        Schema::dropIfExists('transactions');
        Schema::dropIfExists('settings');
    }
};
