<?php

namespace App\Services\Twitch;

use App\Services\Chat\ChatTracker;
use App\Services\Chat\InteractionRecorder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Trata as notificações do EventSub (separado do loop WebSocket para dar para testar).
 */
class EventSubHandler
{
    public const HEARTBEAT_KEY = 'twitch:listener:heartbeat';

    /**
     * Inscrições do EventSub. A condição é preenchida com o id do canal.
     * "scope" é o que o WebSocket exige; "webhook_scopes" soma o que o webhook (App Access Token) exige a mais:
     * para eventos de chat, a Twitch pede user:bot e channel:bot.
     *
     * @return array<int, array{type: string, version: string, condition: array<string, string>, scope: string|null, webhook_scopes?: array<int, string>}>
     */
    public static function subscriptions(string $broadcasterId): array
    {
        $chatScopes = ['user:read:chat', 'user:bot', 'channel:bot'];

        return [
            [
                'type' => 'channel.chat.message',
                'version' => '1',
                'condition' => ['broadcaster_user_id' => $broadcasterId, 'user_id' => $broadcasterId],
                'scope' => 'user:read:chat',
                'webhook_scopes' => $chatScopes,
            ],
            [
                // Subs, Prime, resubs, gift subs e upgrades (mesma permissão do chat).
                'type' => 'channel.chat.notification',
                'version' => '1',
                'condition' => ['broadcaster_user_id' => $broadcasterId, 'user_id' => $broadcasterId],
                'scope' => 'user:read:chat',
                'webhook_scopes' => $chatScopes,
            ],
            [
                'type' => 'channel.channel_points_custom_reward_redemption.add',
                'version' => '1',
                'condition' => ['broadcaster_user_id' => $broadcasterId],
                'scope' => 'channel:read:redemptions',
            ],
            [
                'type' => 'channel.cheer',
                'version' => '1',
                'condition' => ['broadcaster_user_id' => $broadcasterId],
                'scope' => 'bits:read',
            ],
            [
                'type' => 'stream.online',
                'version' => '1',
                'condition' => ['broadcaster_user_id' => $broadcasterId],
                'scope' => null,
            ],
            [
                'type' => 'stream.offline',
                'version' => '1',
                'condition' => ['broadcaster_user_id' => $broadcasterId],
                'scope' => null,
            ],
        ];
    }

    public function __construct(
        private readonly ChatTracker $tracker,
        private readonly InteractionRecorder $interactions,
    ) {}

    /**
     * @param  array<string, mixed>  $message  mensagem completa do WebSocket (metadata + payload)
     */
    public function handleNotification(array $message): void
    {
        $messageId = (string) data_get($message, 'metadata.message_id', '');

        // A Twitch pode reenviar a mesma notificação; processa cada uma uma vez só.
        if ($messageId !== '' && ! Cache::add("eventsub:seen:{$messageId}", true, now()->addMinutes(15))) {
            return;
        }

        $type = (string) data_get($message, 'payload.subscription.type');
        $event = (array) data_get($message, 'payload.event', []);
        $at = Carbon::parse(data_get($message, 'metadata.message_timestamp', now()));

        match ($type) {
            'channel.chat.message' => $this->tracker->recordMessage(
                (string) $event['chatter_user_id'],
                (string) $event['chatter_user_login'],
                (string) $event['chatter_user_name'],
                $at,
            ),
            'channel.chat.notification' => $this->interactions->chatNotification($event, $at),
            'channel.channel_points_custom_reward_redemption.add' => $this->interactions->redemption($event),
            'channel.cheer' => $this->interactions->cheer($event, $messageId, $at),
            'stream.online' => ($event['type'] ?? 'live') === 'live'
                ? $this->tracker->openSession((string) $event['id'], Carbon::parse($event['started_at']))
                : null,
            'stream.offline' => $this->tracker->closeOpenSessions(),
            default => Log::debug('EventSub: tipo ignorado', ['type' => $type]),
        };
    }

    public static function beat(): void
    {
        Cache::forever(self::HEARTBEAT_KEY, now()->toIso8601String());
    }
}
