<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChannelEvent extends Model
{
    /** Resgate da loja de pontos. */
    public const TYPE_REDEMPTION = 'redemption';

    /** Sub nova (paga, Prime ou recebida de presente). */
    public const TYPE_SUB = 'sub';

    /** Renovação de sub compartilhada no chat. */
    public const TYPE_RESUB = 'resub';

    /** Quem deu sub de presente (amount = quantos). */
    public const TYPE_GIFT = 'gift';

    /** Upgrade de Prime ou de presente para sub paga. */
    public const TYPE_UPGRADE = 'upgrade';

    /** Bits (cheer). */
    public const TYPE_BITS = 'bits';

    public const TYPES = [
        self::TYPE_REDEMPTION,
        self::TYPE_SUB,
        self::TYPE_RESUB,
        self::TYPE_GIFT,
        self::TYPE_UPGRADE,
        self::TYPE_BITS,
    ];

    protected $fillable = [
        'twitch_event_id',
        'type',
        'stream_session_id',
        'chatter_id',
        'user_login',
        'user_name',
        'title',
        'cost',
        'amount',
        'tier',
        'months',
        'streak_months',
        'is_prime',
        'is_gift',
        'is_anonymous',
        'related_user_name',
        'user_input',
        'status',
        'occurred_at',
    ];

    protected function casts(): array
    {
        return [
            'occurred_at' => 'datetime',
            'cost' => 'integer',
            'amount' => 'integer',
            'months' => 'integer',
            'streak_months' => 'integer',
            'is_prime' => 'boolean',
            'is_gift' => 'boolean',
            'is_anonymous' => 'boolean',
        ];
    }

    public function chatter(): BelongsTo
    {
        return $this->belongsTo(Chatter::class);
    }

    /**
     * Formato único usado pelo feed do painel e pela ficha de cada pessoa.
     *
     * @return array<string, mixed>
     */
    public function toFeedItem(): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'user_id' => $this->chatter?->twitch_user_id,
            'avatar' => $this->chatter?->profile_image_url,
            'user_name' => $this->user_name,
            'user_login' => $this->user_login,
            'title' => $this->title,
            'cost' => $this->cost,
            'amount' => $this->amount,
            'tier' => $this->tier,
            'months' => $this->months,
            'streak_months' => $this->streak_months,
            'is_prime' => $this->is_prime,
            'is_gift' => $this->is_gift,
            'is_anonymous' => $this->is_anonymous,
            'related_user_name' => $this->related_user_name,
            'user_input' => $this->user_input,
            'occurred_at' => $this->occurred_at->toIso8601String(),
        ];
    }
}
