<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('channel_events', function (Blueprint $table) {
            // Quantidade: bits enviados ou subs presenteados.
            $table->unsignedInteger('amount')->nullable()->after('cost');
            // Tier da sub: 1000, 2000 ou 3000 (Prime também é 1000, com is_prime = true).
            $table->string('tier', 8)->nullable()->after('amount');
            $table->unsignedInteger('months')->nullable()->after('tier');
            $table->unsignedInteger('streak_months')->nullable()->after('months');
            $table->boolean('is_prime')->default(false)->after('streak_months');
            $table->boolean('is_gift')->default(false)->after('is_prime');
            $table->boolean('is_anonymous')->default(false)->after('is_gift');
            // Outra pessoa envolvida: quem presenteou (para quem recebeu) ou quem recebeu (para quem deu).
            $table->string('related_user_name')->nullable()->after('is_anonymous');
            $table->index(['chatter_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::table('channel_events', function (Blueprint $table) {
            $table->dropIndex(['chatter_id', 'type']);
            $table->dropColumn([
                'amount', 'tier', 'months', 'streak_months', 'is_prime', 'is_gift', 'is_anonymous', 'related_user_name',
            ]);
        });
    }
};
