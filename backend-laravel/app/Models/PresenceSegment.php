<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Um período contínuo de uma pessoa no chat durante uma live (entrou → saiu).
 */
class PresenceSegment extends Model
{
    protected $fillable = ['chatter_stream_stat_id', 'started_at', 'ended_at'];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
        ];
    }

    public function stat(): BelongsTo
    {
        return $this->belongsTo(ChatterStreamStat::class, 'chatter_stream_stat_id');
    }
}
