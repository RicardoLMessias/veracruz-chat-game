<?php

namespace App\Console\Commands;

use App\Services\Economy\RewardService;
use Illuminate\Console\Command;

/**
 * A cada minuto: transforma em XP o tempo no chat que ainda não foi pago (com sequência, sub e "ativo").
 */
class AwardPresence extends Command
{
    protected $signature = 'economy:presence';

    protected $description = 'Paga o XP do tempo no chat da live aberta';

    public function handle(RewardService $rewards): int
    {
        $count = $rewards->awardPresence();
        $this->info("XP de presença pago para {$count} pessoa(s).");

        return self::SUCCESS;
    }
}
