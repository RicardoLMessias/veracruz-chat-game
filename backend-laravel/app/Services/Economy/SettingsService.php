<?php

namespace App\Services\Economy;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Lê a tabela `settings` com cache de 60s: mudanças feitas direto no banco valem sem reiniciar nada.
 * Se a chave não existir, usa o valor padrão de SettingsDefaults.
 */
class SettingsService
{
    public const CACHE_KEY = 'economy:settings';

    /**
     * @return array<string, string>
     */
    public function all(): array
    {
        return Cache::remember(self::CACHE_KEY, 60, function () {
            try {
                return DB::table('settings')->pluck('value', 'key')->all();
            } catch (Throwable) {
                return [];
            }
        });
    }

    public function get(string $key): string
    {
        return (string) ($this->all()[$key] ?? SettingsDefaults::all()[$key][0] ?? '');
    }

    public function int(string $key): int
    {
        return (int) $this->get($key);
    }

    public function float(string $key): float
    {
        return (float) $this->get($key);
    }

    public function json(string $key): mixed
    {
        return json_decode($this->get($key), true);
    }

    public function set(string $key, string $value): void
    {
        DB::table('settings')->updateOrInsert(['key' => $key], ['value' => $value, 'updated_at' => now()]);
        Cache::forget(self::CACHE_KEY);
    }
}
