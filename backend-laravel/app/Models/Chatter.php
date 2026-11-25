<?php

namespace App\Models;

use App\Services\Economy\LevelService;
use App\Services\Economy\RewardService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

class Chatter extends Model
{
    protected $fillable = ['twitch_user_id', 'login', 'display_name', 'profile_image_url', 'profile_fetched_at'];

    protected function casts(): array
    {
        return [
            'coins' => 'integer',
            'xp' => 'integer',
            'level' => 'integer',
            'sub_tier' => 'integer',
            'sub_is_prime' => 'boolean',
            'current_streak' => 'integer',
            'best_streak' => 'integer',
        ];
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    public function stats(): HasMany
    {
        return $this->hasMany(ChatterStreamStat::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(ChannelEvent::class);
    }

    public static function remember(string $twitchUserId, string $login, string $displayName): self
    {
        return static::updateOrCreate(
            ['twitch_user_id' => $twitchUserId],
            ['login' => $login, 'display_name' => $displayName],
        );
    }

    /**
     * Totais de todas as lives: tempo, mensagens, resgates, pontos gastos, subs, gifts e bits.
     */
    public function scopeWithTotals(Builder $query): Builder
    {
        $type = fn (string ...$types) => fn ($q) => $q->whereIn('type', $types);

        return $query
            ->withSum('stats as watch_seconds', 'watch_seconds')
            ->withSum('stats as message_count', 'message_count')
            ->withCount('stats as lives_count')
            ->withMax('stats as last_seen_at', 'last_seen_at')
            ->withCount(['events as redemptions_count' => $type(ChannelEvent::TYPE_REDEMPTION)])
            ->withSum(['events as points_spent' => $type(ChannelEvent::TYPE_REDEMPTION)], 'cost')
            ->withCount(['events as subs_count' => $type(ChannelEvent::TYPE_SUB, ChannelEvent::TYPE_RESUB)])
            ->withSum(['events as gifts_given' => $type(ChannelEvent::TYPE_GIFT)], 'amount')
            ->withSum(['events as bits_total' => $type(ChannelEvent::TYPE_BITS)], 'amount');
    }

    /**
     * @return array<string, mixed>
     */
    public function totalsArray(): array
    {
        return [
            'watch_seconds' => (int) $this->watch_seconds,
            'message_count' => (int) $this->message_count,
            'lives_count' => (int) $this->lives_count,
            'redemptions_count' => (int) $this->redemptions_count,
            'points_spent' => (int) $this->points_spent,
            'subs_count' => (int) $this->subs_count,
            'gifts_given' => (int) $this->gifts_given,
            'bits_total' => (int) $this->bits_total,
            'last_seen_at' => $this->last_seen_at ? Carbon::parse($this->last_seen_at)->toIso8601String() : null,
        ];
    }

    /**
     * Saldo da economia: XP, nível (com progresso e título), moedas, sub e sequência.
     *
     * @return array<string, mixed>
     */
    public function economyArray(): array
    {
        $level = app(LevelService::class)->fromXp((int) $this->xp);
        $streak = app(RewardService::class)->effectiveStreak($this, StreamSession::current()
            ?? StreamSession::latest('started_at')->first());

        return [
            'xp' => (int) $this->xp,
            'coins' => (int) $this->coins,
            'level' => $level['level'],
            'title' => $level['title'],
            'xp_in_level' => $level['xp_in_level'],
            'xp_needed' => $level['xp_needed'],
            'sub_tier' => (int) $this->sub_tier,
            'sub_is_prime' => (bool) $this->sub_is_prime,
            'streak' => $streak,
            'best_streak' => (int) $this->best_streak,
        ];
    }
}
