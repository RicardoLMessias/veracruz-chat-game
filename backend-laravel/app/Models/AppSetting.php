<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Throwable;

class AppSetting extends Model
{
    protected $primaryKey = 'key';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = ['key', 'value'];

    protected $hidden = ['value'];

    protected function casts(): array
    {
        return ['value' => 'encrypted'];
    }

    public static function getValue(string $key): ?string
    {
        try {
            return static::find($key)?->value;
        } catch (Throwable) {
            // Banco ainda sem migrations (ou fora do ar): cai no fallback do .env.
            return null;
        }
    }

    public static function setValue(string $key, string $value): void
    {
        static::updateOrCreate(['key' => $key], ['value' => $value]);
    }
}
