<?php

namespace App\Console\Commands;

use App\Models\TwitchAccount;
use App\Services\Chat\ChatterProfiles;
use App\Services\Chat\ChatTracker;
use App\Services\Twitch\TwitchAuthException;
use App\Services\Twitch\TwitchClient;
use App\Services\Twitch\TwitchTokenManager;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Roda a cada minuto pelo agendador: confere se a live está no ar e quem está no chat.
 */
class TrackChatters extends Command
{
    public const HEARTBEAT_KEY = 'twitch:tracker:last_run';

    protected $signature = 'twitch:track-chatters';

    protected $description = 'Atualiza a sessão da live e a presença de quem está no chat';

    public function handle(TwitchClient $twitch, TwitchTokenManager $tokens, ChatTracker $tracker, ChatterProfiles $profiles): int
    {
        $account = TwitchAccount::latest('updated_at')->first();

        if (! $account) {
            $this->warn('Nenhum canal conectado ainda.');

            return self::SUCCESS;
        }

        try {
            $account = $tokens->ensureFresh($account);
            $stream = $twitch->stream($account->access_token, $account->twitch_id);
            $session = $tracker->syncStream($stream);

            if (! $session) {
                $this->line('Offline.');
                Cache::forever(self::HEARTBEAT_KEY, now()->toIso8601String());

                return self::SUCCESS;
            }

            if (! $account->hasScope('moderator:read:chatters')) {
                $this->warn('Falta a permissão moderator:read:chatters. Faça login de novo no dashboard.');

                return self::SUCCESS;
            }

            $chatters = $twitch->chatters($account->access_token, $account->twitch_id);
            $tracker->recordPresence($session, $chatters);
            Cache::forever(self::HEARTBEAT_KEY, now()->toIso8601String());

            // Fotos de perfil de quem é novo (não atrapalha a presença se falhar).
            try {
                $profiles->refresh($account->access_token);
            } catch (Throwable $e) {
                report($e);
            }

            $this->info('Ao vivo: '.count($chatters).' pessoas no chat.');
        } catch (TwitchAuthException $e) {
            $this->warn('Token da Twitch inválido: faça login de novo no dashboard.');

            return self::FAILURE;
        } catch (Throwable $e) {
            report($e);
            $this->error('Twitch indisponível: '.$e->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
