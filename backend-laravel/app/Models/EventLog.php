<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EventLog extends Model
{
    protected $table = 'events_log';

    public $timestamps = false;

    protected $fillable = ['message_id', 'type', 'payload', 'received_at', 'processed_at', 'error'];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'received_at' => 'datetime',
            'processed_at' => 'datetime',
        ];
    }
}
