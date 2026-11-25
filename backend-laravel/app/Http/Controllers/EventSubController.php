<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessTwitchEvent;
use App\Models\EventLog;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Webhook do EventSub: a Twitch faz POST aqui a cada evento.
 * Confere a assinatura, grava o evento bruto e manda processar na fila.
 */
class EventSubController extends Controller
{
    public const LAST_RECEIVED_KEY = 'twitch:webhook:last_received';

    private const MAX_AGE_SECONDS = 600;

    public function __invoke(Request $request): Response
    {
        $messageId = (string) $request->header('Twitch-Eventsub-Message-Id', '');
        $timestamp = (string) $request->header('Twitch-Eventsub-Message-Timestamp', '');
        $signature = (string) $request->header('Twitch-Eventsub-Message-Signature', '');
        $type = (string) $request->header('Twitch-Eventsub-Message-Type', '');
        $body = $request->getContent();
        $secret = (string) config('services.twitch.eventsub_secret');

        if ($secret === '' || $messageId === '' || $timestamp === '') {
            return response('', 403);
        }

        $expected = 'sha256='.hash_hmac('sha256', $messageId.$timestamp.$body, $secret);

        if (! hash_equals($expected, $signature)) {
            Log::warning('EventSub: assinatura inválida', ['message_id' => $messageId]);

            return response('', 403);
        }

        try {
            $sentAt = Carbon::parse($timestamp);
        } catch (\Throwable) {
            return response('', 403);
        }

        if ($sentAt->lt(now()->subSeconds(self::MAX_AGE_SECONDS))) {
            return response('', 403);
        }

        $payload = json_decode($body, true) ?: [];
        Cache::forever(self::LAST_RECEIVED_KEY, now()->toIso8601String());

        return match ($type) {
            'webhook_callback_verification' => response((string) ($payload['challenge'] ?? ''), 200)
                ->header('Content-Type', 'text/plain'),
            'notification' => $this->notification($messageId, $sentAt, $payload),
            'revocation' => $this->revocation($payload),
            default => response('', 204),
        };
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function notification(string $messageId, Carbon $sentAt, array $payload): Response
    {
        $inserted = EventLog::query()->insertOrIgnore([
            'message_id' => $messageId,
            'type' => (string) data_get($payload, 'subscription.type', ''),
            'payload' => json_encode($payload),
            'received_at' => $sentAt,
        ]);

        // 0 = esse message_id já tinha chegado (reenvio da Twitch): não processa de novo.
        if ($inserted > 0) {
            ProcessTwitchEvent::dispatch((int) EventLog::where('message_id', $messageId)->value('id'));
        }

        return response('', 204);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function revocation(array $payload): Response
    {
        Log::warning('EventSub: inscrição revogada', [
            'type' => data_get($payload, 'subscription.type'),
            'status' => data_get($payload, 'subscription.status'),
        ]);

        return response('', 204);
    }
}
