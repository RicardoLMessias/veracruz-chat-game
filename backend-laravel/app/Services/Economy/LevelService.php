<?php

namespace App\Services\Economy;

/**
 * Curva de níveis: XP para ir do nível n ao n+1 = level_base × n ^ level_exponent (arredondado).
 * O nível é sempre calculado a partir do XP total.
 */
class LevelService
{
    public function __construct(private readonly SettingsService $settings) {}

    public function xpForNext(int $level): int
    {
        return (int) round($this->settings->float('level_base') * ($level ** $this->settings->float('level_exponent')));
    }

    /**
     * @return array{level: int, xp_in_level: int, xp_needed: int, title: string}
     */
    public function fromXp(int $xp): array
    {
        $level = 1;
        $remaining = max(0, $xp);

        while ($remaining >= ($need = $this->xpForNext($level)) && $level < 1000) {
            $remaining -= $need;
            $level++;
        }

        return [
            'level' => $level,
            'xp_in_level' => $remaining,
            'xp_needed' => $this->xpForNext($level),
            'title' => $this->titleFor($level),
        ];
    }

    public function levelFromXp(int $xp): int
    {
        return $this->fromXp($xp)['level'];
    }

    public function titleFor(int $level): string
    {
        $title = 'Andarilho';

        foreach ((array) $this->settings->json('level_titles') as [$minLevel, $name]) {
            if ($level >= (int) $minLevel) {
                $title = (string) $name;
            }
        }

        return $title;
    }
}
