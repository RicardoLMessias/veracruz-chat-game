<?php

namespace App\Services\Economy;

use App\Models\ChannelEvent;
use App\Models\Chatter;
use App\Models\ChatterStreamStat;
use App\Models\StreamSession;
use App\Models\Transaction;
use App\Services\Chat\IgnoredChatters;
use Illuminate\Support\Facades\Cache;

/**
 * Regras de quanto cada interação rende (docs/economia-e-overlay.md):
 * - XP de todas as interações; moedas só de subs, presentes e bits (missões e pontos do canal virão depois).
 * - Multiplicador de sub em tudo; sequência e "ativo no chat" só no XP de presença.
 * - Mensagens e resgates: no máximo N por minuto dão XP.
 */
class RewardService
{
    public function __construct(
        private readonly EconomyService $economy,
        private readonly SettingsService $settings,
        private readonly IgnoredChatters $ignored,
    ) {}

    /**
     * Paga o XP do tempo no chat que ainda não virou XP, em todas as pessoas da live aberta.
     *
     * @return int quantas pessoas receberam
     */
    public function awardPresence(): int
    {
        $session = StreamSession::current();

        if (! $session) {
            return 0;
        }

        $paid = 0;
        $stats = ChatterStreamStat::with('chatter')
            ->where('stream_session_id', $session->id)
            ->whereColumn('watch_seconds', '>', 'xp_awarded_seconds')
            ->get();

        foreach ($stats as $stat) {
            if ($this->ignored->isIgnored($stat->chatter->login)) {
                continue;
            }

            $this->processStreak($stat, $stat->chatter, $session);

            $seconds = $stat->watch_seconds - $stat->xp_awarded_seconds;
            $active = $stat->last_message_at !== null
                && $stat->last_message_at->greaterThanOrEqualTo(now()->subMinutes($this->settings->int('active_window_min')));
            $streak = $this->effectiveStreak($stat->chatter, $session);
            $mult = $this->economy->subMultiplier($stat->chatter)
                * $this->streakMultiplier($streak)
                * ($active ? $this->settings->float('active_multiplier') : 1.0);
            $xp = EconomyService::stochasticRound($this->settings->float('presence_xp_per_min') * ($seconds / 60) * $mult);

            $this->economy->grant($stat->chatter_id, Transaction::PRESENCE, 0, $xp, [
                'seconds' => $seconds,
                'mult' => round($mult, 3),
                'active' => $active,
                'streak' => $streak,
            ], $session->id);

            $stat->forceFill(['xp_awarded_seconds' => $stat->watch_seconds])->save();
            $paid++;
        }

        return $paid;
    }

    /**
     * Uma mensagem no chat (já contada na estatística). Dá XP até o limite por minuto.
     */
    public function onMessage(Chatter $chatter, ?int $sessionId): void
    {
        if ($this->ignored->isIgnored($chatter->login)
            || ! $this->withinRate("msg:{$chatter->id}", $this->settings->int('message_xp_per_minute'))) {
            return;
        }

        $xp = EconomyService::stochasticRound($this->settings->int('message_xp') * $this->economy->subMultiplier($chatter));
        $this->economy->grant($chatter->id, Transaction::MESSAGE, 0, $xp, [], $sessionId);
    }

    /**
     * Uma interação recém-registrada (resgate, sub, resub, presente, upgrade, bits).
     */
    public function onEvent(ChannelEvent $event): void
    {
        $chatter = $event->chatter;

        if (! $chatter || (bool) $event->is_anonymous || $this->ignored->isIgnored($chatter->login)) {
            return;
        }

        $session = $event->stream_session_id;
        $meta = ['event_id' => $event->id];

        switch ($event->type) {
            case ChannelEvent::TYPE_REDEMPTION:
                if ($this->withinRate("redemption:{$chatter->id}", $this->settings->int('redemption_xp_per_minute'))) {
                    $xp = $this->settings->int('redemption_xp') * $this->economy->subMultiplier($chatter);
                    $this->economy->grant($chatter->id, Transaction::REDEMPTION, 0, EconomyService::stochasticRound($xp),
                        $meta + ['reward' => $event->title], $session);
                }
                break;

            case ChannelEvent::TYPE_SUB:
            case ChannelEvent::TYPE_RESUB:
                // Primeiro atualiza o tier: quem acabou de assinar já ganha com o multiplicador novo.
                $this->setTier($chatter, $event->tier, (bool) $event->is_prime);
                $mult = $this->economy->subMultiplier($chatter);
                $type = (bool) $event->is_gift && $event->type === ChannelEvent::TYPE_SUB
                    ? Transaction::GIFT_RECEIVED
                    : ($event->type === ChannelEvent::TYPE_RESUB ? Transaction::RESUB : Transaction::SUB);

                $this->economy->grant(
                    $chatter->id,
                    $type,
                    EconomyService::stochasticRound($this->settings->int('sub_coins') * $mult),
                    EconomyService::stochasticRound($this->settings->int('sub_xp') * $mult),
                    $meta + ['tier' => $event->tier, 'prime' => (bool) $event->is_prime, 'months' => $event->months],
                    $session,
                );
                break;

            case ChannelEvent::TYPE_UPGRADE:
                $this->setTier($chatter, $event->tier, false);
                break;

            case ChannelEvent::TYPE_GIFT:
                $count = max(1, (int) $event->amount);
                $mult = $this->economy->subMultiplier($chatter);

                $this->economy->grant(
                    $chatter->id,
                    Transaction::GIFT_GIVEN,
                    EconomyService::stochasticRound($count * $this->settings->int('gift_coins_per_sub') * $mult),
                    EconomyService::stochasticRound($count * $this->settings->int('gift_xp_per_sub') * $mult),
                    $meta + ['subs' => $count, 'tier' => $event->tier],
                    $session,
                );
                break;

            case ChannelEvent::TYPE_BITS:
                $hundreds = max(0, (int) $event->amount) / 100;
                $mult = $this->economy->subMultiplier($chatter);

                $this->economy->grant(
                    $chatter->id,
                    Transaction::BITS,
                    EconomyService::stochasticRound($hundreds * $this->settings->int('bits_coins_per_100') * $mult),
                    EconomyService::stochasticRound($hundreds * $this->settings->int('bits_xp_per_100') * $mult),
                    $meta + ['bits' => (int) $event->amount],
                    $session,
                );
                break;
        }
    }

    /**
     * Sequência de lives: conta a live quando a pessoa passa de streak_min_minutes nela. Se a última
     * live contada era a imediatamente anterior, a sequência cresce; senão, recomeça em 1.
     */
    public function processStreak(ChatterStreamStat $stat, Chatter $chatter, StreamSession $session): void
    {
        if ($stat->counted_for_streak || $stat->watch_seconds < $this->settings->int('streak_min_minutes') * 60) {
            return;
        }

        $previous = $this->previousSession($session);
        $chatter->current_streak = $previous && $chatter->last_counted_session_id === $previous->id
            ? $chatter->current_streak + 1
            : 1;
        $chatter->best_streak = max($chatter->best_streak, $chatter->current_streak);
        $chatter->last_counted_session_id = $session->id;
        $chatter->save();

        $stat->forceFill(['counted_for_streak' => true])->save();
    }

    /**
     * Sequência que vale agora: se a pessoa faltou na live anterior, a sequência já está quebrada.
     */
    public function effectiveStreak(Chatter $chatter, ?StreamSession $session): int
    {
        if (! $session || ! $chatter->last_counted_session_id) {
            return 0;
        }

        if ($chatter->last_counted_session_id === $session->id) {
            return $chatter->current_streak;
        }

        return $chatter->last_counted_session_id === $this->previousSession($session)?->id
            ? $chatter->current_streak
            : 0;
    }

    public function streakMultiplier(int $streak): float
    {
        if ($streak < 2) {
            return 1.0;
        }

        return 1 + min(($streak - 1) * $this->settings->float('streak_bonus_per_live'), $this->settings->float('streak_bonus_max'));
    }

    public static function tierNumber(?string $tier, bool $prime = false): int
    {
        if ($prime) {
            return 1;
        }

        return match ((string) $tier) {
            '1000' => 1,
            '2000' => 2,
            '3000' => 3,
            default => 1,
        };
    }

    private function setTier(Chatter $chatter, ?string $tier, bool $prime): void
    {
        $chatter->sub_tier = self::tierNumber($tier, $prime);
        $chatter->sub_is_prime = $prime;
        $chatter->save();
    }

    private function previousSession(StreamSession $session): ?StreamSession
    {
        return StreamSession::where('started_at', '<', $session->started_at)->latest('started_at')->first();
    }

    /**
     * Janela fixa de 1 minuto: retorna true enquanto não passou do limite neste minuto.
     */
    private function withinRate(string $key, int $limit): bool
    {
        $bucket = 'economy:rate:'.$key.':'.now()->format('YmdHi');
        Cache::add($bucket, 0, now()->addMinutes(2));

        return Cache::increment($bucket) <= $limit;
    }
}
