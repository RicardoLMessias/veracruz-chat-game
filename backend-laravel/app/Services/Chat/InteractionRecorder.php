<?php

namespace App\Services\Chat;

use App\Models\ChannelEvent;
use App\Models\Chatter;
use App\Models\StreamSession;
use App\Services\Economy\RewardService;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * Grava cada interação (resgate, sub, resub, gift, upgrade, bits) ligada à pessoa que fez.
 * Interações anônimas ficam sem pessoa (chatter_id nulo) e com nome "Anônimo".
 */
class InteractionRecorder
{
    public const ANONYMOUS = 'Anônimo';

    public function __construct(private readonly RewardService $rewards) {}

    /**
     * @param  array<string, mixed>  $event  channel.channel_points_custom_reward_redemption.add
     */
    public function redemption(array $event): void
    {
        $this->store('redemption:'.$event['id'], ChannelEvent::TYPE_REDEMPTION, [
            'id' => $event['user_id'],
            'login' => $event['user_login'],
            'name' => $event['user_name'],
        ], [
            'title' => $event['reward']['title'] ?? null,
            'cost' => $event['reward']['cost'] ?? null,
            'user_input' => $this->text($event['user_input'] ?? null),
            'status' => $event['status'] ?? null,
        ], Carbon::parse($event['redeemed_at'] ?? now()));
    }

    /**
     * Notificações do chat (channel.chat.notification): é daqui que vêm subs, Prime, resubs e gifts,
     * porque é o único evento da Twitch que diz se a sub foi Prime.
     *
     * @param  array<string, mixed>  $event
     */
    public function chatNotification(array $event, CarbonInterface $at): void
    {
        $type = (string) ($event['notice_type'] ?? '');
        $id = 'notice:'.($event['message_id'] ?? '');
        $anonymous = (bool) ($event['chatter_is_anonymous'] ?? false);
        $user = $anonymous ? null : [
            'id' => $event['chatter_user_id'] ?? null,
            'login' => $event['chatter_user_login'] ?? null,
            'name' => $event['chatter_user_name'] ?? null,
        ];
        $message = $this->text($event['message']['text'] ?? null);

        switch ($type) {
            case 'sub':
                $sub = (array) ($event['sub'] ?? []);
                $this->store($id, ChannelEvent::TYPE_SUB, $user, [
                    'tier' => $sub['sub_tier'] ?? null,
                    'is_prime' => (bool) ($sub['is_prime'] ?? false),
                    'months' => $sub['duration_months'] ?? null,
                    'user_input' => $message,
                ], $at);
                break;

            case 'resub':
                $resub = (array) ($event['resub'] ?? []);
                $gifted = (bool) ($resub['is_gift'] ?? false);
                $this->store($id, ChannelEvent::TYPE_RESUB, $user, [
                    'tier' => $resub['sub_tier'] ?? null,
                    'is_prime' => (bool) ($resub['is_prime'] ?? false),
                    'is_gift' => $gifted,
                    'months' => $resub['cumulative_months'] ?? null,
                    'streak_months' => $resub['streak_months'] ?? null,
                    'related_user_name' => $gifted ? $this->gifterName($resub) : null,
                    'user_input' => $message,
                ], $at);
                break;

            case 'sub_gift':
                $gift = (array) ($event['sub_gift'] ?? []);
                $gifter = $anonymous ? self::ANONYMOUS : ($event['chatter_user_name'] ?? null);

                // Quem recebeu ganha uma sub (de presente).
                $this->store($id.':received', ChannelEvent::TYPE_SUB, [
                    'id' => $gift['recipient_user_id'] ?? null,
                    'login' => $gift['recipient_user_login'] ?? null,
                    'name' => $gift['recipient_user_name'] ?? null,
                ], [
                    'tier' => $gift['sub_tier'] ?? null,
                    'is_gift' => true,
                    'months' => $gift['duration_months'] ?? null,
                    'related_user_name' => $gifter,
                ], $at);

                // Presente avulso conta para quem deu. Se faz parte de um pacote (community gift),
                // quem deu já foi contado uma vez no community_sub_gift.
                if (empty($gift['community_gift_id'])) {
                    $this->store($id.':given', ChannelEvent::TYPE_GIFT, $user, [
                        'amount' => 1,
                        'tier' => $gift['sub_tier'] ?? null,
                        'is_anonymous' => $anonymous,
                        'related_user_name' => $gift['recipient_user_name'] ?? null,
                    ], $at);
                }
                break;

            case 'community_sub_gift':
                $gift = (array) ($event['community_sub_gift'] ?? []);
                $this->store('community:'.($gift['id'] ?? $event['message_id'] ?? ''), ChannelEvent::TYPE_GIFT, $user, [
                    'amount' => (int) ($gift['total'] ?? 1),
                    'tier' => $gift['sub_tier'] ?? null,
                    'is_anonymous' => $anonymous,
                ], $at);
                break;

            case 'prime_paid_upgrade':
                $this->store($id, ChannelEvent::TYPE_UPGRADE, $user, [
                    'title' => 'Prime → paga',
                    'tier' => $event['prime_paid_upgrade']['sub_tier'] ?? null,
                ], $at);
                break;

            case 'gift_paid_upgrade':
                $upgrade = (array) ($event['gift_paid_upgrade'] ?? []);
                $this->store($id, ChannelEvent::TYPE_UPGRADE, $user, [
                    'title' => 'Presente → paga',
                    'related_user_name' => $this->gifterName($upgrade),
                ], $at);
                break;
        }
    }

    /**
     * @param  array<string, mixed>  $event  channel.cheer
     */
    public function cheer(array $event, string $eventId, CarbonInterface $at): void
    {
        $anonymous = (bool) ($event['is_anonymous'] ?? false);

        $this->store('cheer:'.$eventId, ChannelEvent::TYPE_BITS, $anonymous ? null : [
            'id' => $event['user_id'] ?? null,
            'login' => $event['user_login'] ?? null,
            'name' => $event['user_name'] ?? null,
        ], [
            'amount' => (int) ($event['bits'] ?? 0),
            'is_anonymous' => $anonymous,
            'user_input' => $this->text($event['message'] ?? null),
        ], $at);
    }

    /**
     * @param  array{id: mixed, login: mixed, name: mixed}|null  $user
     * @param  array<string, mixed>  $attributes
     */
    private function store(string $eventId, string $type, ?array $user, array $attributes, CarbonInterface $at): void
    {
        $chatter = ($user && ! empty($user['id']))
            ? Chatter::remember((string) $user['id'], (string) $user['login'], (string) $user['name'])
            : null;

        $event = ChannelEvent::firstOrCreate(
            ['twitch_event_id' => $eventId],
            array_merge([
                'type' => $type,
                'stream_session_id' => StreamSession::current()?->id,
                'chatter_id' => $chatter?->id,
                'user_login' => $chatter?->login,
                'user_name' => $chatter?->display_name ?? self::ANONYMOUS,
                'is_anonymous' => $chatter === null,
                'occurred_at' => $at,
            ], $attributes),
        );

        // Só paga XP/moedas na primeira vez que o evento chega (reenvios não pagam de novo).
        if ($event->wasRecentlyCreated) {
            $this->rewards->onEvent($event);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function gifterName(array $data): string
    {
        return ($data['gifter_is_anonymous'] ?? false) ? self::ANONYMOUS : (string) ($data['gifter_user_name'] ?? self::ANONYMOUS);
    }

    private function text(mixed $value): ?string
    {
        $value = is_string($value) ? trim($value) : '';

        return $value === '' ? null : $value;
    }
}
