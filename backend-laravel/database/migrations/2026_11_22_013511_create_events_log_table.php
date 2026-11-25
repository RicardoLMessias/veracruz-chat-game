<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Evento bruto recebido pelo webhook do EventSub. O message_id único impede processar reenvios.
        Schema::create('events_log', function (Blueprint $table) {
            $table->id();
            $table->string('message_id')->unique();
            $table->string('type')->index();
            $table->json('payload');
            $table->timestamp('received_at')->index();
            $table->timestamp('processed_at')->nullable()->index();
            $table->text('error')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('events_log');
    }
};
