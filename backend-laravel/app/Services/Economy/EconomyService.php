<?php

namespace App\Services\Economy;

use App\Models\Chatter;
use App\Models\Transaction;
use Illuminate\Support\Facades\DB;

/**
 * Único ponto por onde moedas e XP entram ou saem. Grava a linha no livro-caixa e atualiza o saldo
 * da pessoa na mesma transação de banco, com a linha da pessoa travada: o saldo sempre bate com a
 * soma do livro-caixa.
 */
class EconomyService
{
    public function __construct(
        private readonly SettingsService $settings,
        private readonly LevelService $levels,
    ) {}

    /**
     * @param  array<string, mixed>  $meta
     * @return array{coins: int, xp: int, level: int, leveled_up: bool, transaction_id: int|null}
     */
    public function grant(
        int $chatterId,
        string $type,
        int $coins,
        int $xp,
        array $meta = [],
        ?int $streamSessionId = null,
        bool $allowNegative = false,
    ): array {
        return DB::transaction(function () use ($chatterId, $type, $coins, $xp, $meta, $streamSessionId, $allowNegative) {
            $chatter = Chatter::whereKey($chatterId)->lockForUpdate()->firstOrFail();

            if ($coins < 0 && $chatter->coins + $coins < 0 && ! $allowNegative) {
                throw new InsufficientFundsException("Saldo insuficiente: tem {$chatter->coins}, precisa de ".abs($coins).'.');
            }

            // XP nunca fica negativo.
            $xp = max($xp, -$chatter->xp);

            if ($coins === 0 && $xp === 0) {
                return $this->result($chatter, false, null);
            }

            $transaction = Transaction::create([
                'chatter_id' => $chatter->id,
                'stream_session_id' => $streamSessionId,
                'type' => $type,
                'coins_delta' => $coins,
                'xp_delta' => $xp,
                'meta' => $meta ?: null,
            ]);

            $before = $chatter->level;
            $chatter->coins += $coins;
            $chatter->xp += $xp;
            $chatter->level = $this->levels->levelFromXp($chatter->xp);
            $chatter->save();

            return $this->result($chatter, $chatter->level > $before, $transaction->id);
        });
    }

    /**
     * Multiplicador de sub: Tier 1 e Prime ×1,25, Tier 2 ×1,5, Tier 3 ×2 (valores em settings).
     */
    public function subMultiplier(Chatter $chatter): float
    {
        return match ((int) $chatter->sub_tier) {
            1 => $this->settings->float('sub_mult_t1'),
            2 => $this->settings->float('sub_mult_t2'),
            3 => $this->settings->float('sub_mult_t3'),
            default => 1.0,
        };
    }

    /**
     * Arredondamento estocástico: 2,3 vira 3 com 30% de chance e 2 com 70%. Na média, nada se perde.
     */
    public static function stochasticRound(float $value): int
    {
        $base = (int) floor($value);

        return $base + ((mt_rand() / mt_getrandmax()) < ($value - $base) ? 1 : 0);
    }

    /**
     * @return array{coins: int, xp: int, level: int, leveled_up: bool, transaction_id: int|null}
     */
    private function result(Chatter $chatter, bool $leveledUp, ?int $transactionId): array
    {
        return [
            'coins' => (int) $chatter->coins,
            'xp' => (int) $chatter->xp,
            'level' => (int) $chatter->level,
            'leveled_up' => $leveledUp,
            'transaction_id' => $transactionId,
        ];
    }
}
