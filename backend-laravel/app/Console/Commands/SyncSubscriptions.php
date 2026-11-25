<?php

namespace App\Console\Commands;

use App\Models\Chatter;
use App\Models\TwitchAccount;
use App\Services\Economy\RewardService;
use App\Services\Twitch\TwitchAuthException;
use App\Services\Twitch\TwitchClient;
use App\Services\Twitch\TwitchTokenManager;
use Illuminate\Console\Command;
use Throwable;

/**
 * De hora em hora: alinha o tier de sub de cada pessoa com a Twitch. Pega quem já era sub antes do
 * sistema existir, resubs não compartilhados no chat e zera quem deixou de ser sub.
 */
class SyncSubscriptions extends Command
{
    protected $signature = 'twitch:sync-subs';

    protected $description = 'Sincroniza o tier de sub de cada pessoa com a Twitch';

    public function handle(TwitchClient $twitch, TwitchTokenManager $tokens): int
    {
        $account = TwitchAccount::latest('updated_at')->first();

        if (! $account) {
            $this->warn('Nenhum canal conectado.');

            return self::SUCCESS;
        }

        if (! $account->hasScope('channel:read:subscriptions')) {
            $this->warn('Falta a permissão channel:read:subscriptions. Autorize no dashboard.');

            return self::SUCCESS;
        }

        try {
            $account = $tokens->ensureFresh($account);
            $subs = collect($twitch->subscriptions($account->access_token, $account->twitch_id))
                ->reject(fn ($s) => (string) $s['user_id'] === $account->twitch_id);
        } catch (TwitchAuthException) {
            $this->warn('Token inválido: faça login de novo no dashboard.');

            return self::FAILURE;
        } catch (Throwable $e) {
            report($e);
            $this->error('Twitch indisponível: '.$e->getMessage());

            return self::FAILURE;
        }

        $ids = [];

        foreach ($subs as $sub) {
            $chatter = Chatter::remember((string) $sub['user_id'], (string) $sub['user_login'], (string) $sub['user_name']);
            $tier = RewardService::tierNumber($sub['tier'] ?? null);

            if ($chatter->sub_tier !== $tier) {
                // Prime aparece como Tier 1 na API; mantém a marca de Prime se já sabíamos.
                $chatter->forceFill([
                    'sub_tier' => $tier,
                    'sub_is_prime' => $tier === 1 && $chatter->sub_is_prime,
                ])->save();
            }

            $ids[] = $chatter->id;
        }

        $ended = Chatter::where('sub_tier', '>', 0)->whereNotIn('id', $ids)->update(['sub_tier' => 0, 'sub_is_prime' => false]);

        $this->info(count($ids)." sub(s) ativos; {$ended} deixaram de ser sub.");

        return self::SUCCESS;
    }
}
