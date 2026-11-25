<?php

namespace App\Jobs;

use App\Models\EventLog;
use App\Services\Twitch\EventSubHandler;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Processa um evento recebido pelo webhook, fora da requisição (a Twitch exige resposta rápida).
 */
class ProcessTwitchEvent implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public int $eventLogId) {}

    public function handle(EventSubHandler $handler): void
    {
        $log = EventLog::find($this->eventLogId);

        if (! $log || $log->processed_at !== null) {
            return;
        }

        $payload = $log->payload;

        // Mesmo formato que o handler já recebia pelo WebSocket.
        $handler->handleNotification([
            'metadata' => [
                'message_id' => $log->message_id,
                'message_type' => 'notification',
                'message_timestamp' => $log->received_at->toIso8601String(),
            ],
            'payload' => [
                'subscription' => $payload['subscription'] ?? [],
                'event' => $payload['event'] ?? [],
            ],
        ]);

        $log->forceFill(['processed_at' => now(), 'error' => null])->save();
    }

    public function failed(Throwable $e): void
    {
        EventLog::whereKey($this->eventLogId)->update(['error' => mb_substr($e->getMessage(), 0, 2000)]);
    }
}
