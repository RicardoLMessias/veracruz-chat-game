<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ChatterStreamStat extends Model
{
    protected $fillable = [
        'stream_session_id',
        'chatter_id',
        'first_seen_at',
        'last_seen_at',
        'watch_seconds',
        'message_count',
        'last_message_at',
    ];

    protected function casts(): array
    {
        return [
            'first_seen_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'last_message_at' => 'datetime',
            'watch_seconds' => 'integer',
            'message_count' => 'integer',
        ];
    }

    public function chatter(): BelongsTo
    {
        return $this->belongsTo(Chatter::class);
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(StreamSession::class, 'stream_session_id');
    }

    public function segments(): HasMany
    {
        return $this->hasMany(PresenceSegment::class)->orderBy('started_at');
    }
}
