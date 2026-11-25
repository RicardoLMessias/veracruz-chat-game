<?php

use App\Models\TwitchAccount;
use App\Services\Chat\ChatTracker;
use App\Services\Twitch\TwitchAuthException;
use App\Services\Twitch\TwitchTokenManager;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

// A Twitch exige validar tokens de usuário pelo menos a cada hora.
// Rode `php artisan schedule:work` junto com o servidor para isso acontecer sozinho.
Artisan::command('twitch:validate-tokens', function (TwitchTokenManager $tokens) {
    TwitchAccount::query()->each(function (TwitchAccount $account) use ($tokens) {
        try {
            $tokens->ensureFresh($account);
            $this->info("{$account->login}: ok");
        } catch (TwitchAuthException) {
            $this->warn("{$account->login}: token inválido, precisa logar de novo");
        } catch (Throwable $e) {
            $this->error("{$account->login}: Twitch indisponível ({$e->getMessage()})");
        }
    });
})->purpose('Valida e renova os tokens da Twitch salvos');

Schedule::command('twitch:validate-tokens')->hourly()->withoutOverlapping();

// Presença no chat: confere a lista de quem está no chat a cada CHAT_PRESENCE_INTERVAL segundos (padrão 15).
$presence = Schedule::command('twitch:track-chatters')->withoutOverlapping(2);

match (ChatTracker::interval()) {
    10 => $presence->everyTenSeconds(),
    15 => $presence->everyFifteenSeconds(),
    20 => $presence->everyTwentySeconds(),
    30 => $presence->everyThirtySeconds(),
    default => $presence->everyMinute(),
};

// Economia: XP do tempo no chat a cada minuto; tier de sub alinhado com a Twitch a cada hora.
Schedule::command('economy:presence')->everyMinute()->withoutOverlapping();
Schedule::command('twitch:sync-subs')->hourly()->withoutOverlapping();

// Confere se as inscrições do webhook continuam ativas (o painel avisa se alguma cair).
Schedule::command('twitch:subscribe --status')->everyFiveMinutes()->withoutOverlapping();

// Processa a fila dos eventos do webhook. Localmente, prefira deixar `php artisan queue:work` aberto
// (tempo real); na HostGator, sem processo permanente, é isto que esvazia a fila a cada minuto.
Schedule::command('queue:work --stop-when-empty --max-time=50')->everyMinute()->withoutOverlapping();
