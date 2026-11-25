<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StreamSession extends Model
{
    protected $fillable = ['twitch_stream_id', 'title', 'game_name', 'started_at', 'ended_at'];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
        ];
    }

    public function stats(): HasMany
    {
        return $this->hasMany(ChatterStreamStat::class);
    }

    public static function current(): ?self
    {
        return static::whereNull('ended_at')->latest('started_at')->first();
    }
}
