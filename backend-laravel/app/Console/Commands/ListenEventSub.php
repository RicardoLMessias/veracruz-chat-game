<?php

namespace App\Console\Commands;

use App\Models\TwitchAccount;
use App\Services\Twitch\EventSubHandler;
use App\Services\Twitch\TwitchAuthException;
use App\Services\Twitch\TwitchClient;
use App\Services\Twitch\TwitchTokenManager;
use Composer\CaBundle\CaBundle;
use Illuminate\Console\Command;
use RuntimeException;
use Throwable;
use WebSocket\Client;
use WebSocket\Exception\ConnectionTimeoutException;
use WebSocket\Middleware\CloseHandler;
use WebSocket\Middleware\PingResponder;

/**
 * Fica conectado ao EventSub da Twitch via WebSocket e grava mensagens do chat (contagem),
 * resgates da loja de pontos e início/fim da live. Rode e deixe aberto: php artisan twitch:listen
 */
class ListenEventSub extends Command
{
    private const EVENTSUB_URL = 'wss://eventsub.wss.twitch.tv/ws';

    protected $signature = 'twitch:listen';

    protected $description = 'Escuta o chat e os resgates do canal em tempo real (EventSub WebSocket)';

    private int $keepaliveSeconds = 10;

    public function __construct(
        private readonly TwitchClient $twitch,
        private readonly TwitchTokenManager $tokens,
        private readonly EventSubHandler $handler,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $backoff = 1;

        while (true) {
            try {
                $this->runSession();
                $backoff = 1;
            } catch (TwitchAuthException $e) {
                $this->error('Token da Twitch inválido: faça login de novo no dashboard. Tentando em 60s.');
                sleep(60);
            } catch (Throwable $e) {
                $this->warn("Conexão caiu ({$e->getMessage()}). Reconectando em {$backoff}s.");
                sleep($backoff);
                $backoff = min($backoff * 2, 60);
            }
        }
    }

    /**
     * Uma sessão do EventSub: conecta, inscreve e processa até cair ou a Twitch pedir reconexão.
     */
    private function runSession(): void
    {
        $account = $this->account();
        $client = $this->connect(self::EVENTSUB_URL);
        $sessionId = $this->awaitWelcome($client);
        $skipped = $this->subscribe($account, $sessionId);
        $nextScopeCheck = now()->addMinute();

        while (true) {
            $message = $this->receive($client);
            EventSubHandler::beat();

            // Se faltava permissão e você autorizou depois no dashboard, reconecta para se inscrever.
            if ($skipped !== [] && now()->greaterThan($nextScopeCheck)) {
                $nextScopeCheck = now()->addMinute();
                $fresh = $account->fresh();

                if ($fresh && collect($skipped)->contains(fn ($scope) => $fresh->hasScope($scope))) {
                    $client->disconnect();
                    $this->info('Novas permissões detectadas. Reconectando.');

                    return;
                }
            }

            $type = data_get($message, 'metadata.message_type');

            if ($type === 'notification') {
                $this->handler->handleNotification($message);
                $this->line('<fg=gray>'.now()->format('H:i:s').'</> '.data_get($message, 'payload.subscription.type'));
            } elseif ($type === 'session_reconnect') {
                // A Twitch manda migrar para outro servidor; as inscrições vão junto.
                $newClient = $this->connect((string) data_get($message, 'payload.session.reconnect_url'));
                $this->awaitWelcome($newClient);
                $client->disconnect();
                $client = $newClient;
                $this->info('Reconectado a pedido da Twitch.');
            } elseif ($type === 'revocation') {
                $this->warn('Inscrição revogada: '.data_get($message, 'payload.subscription.type').' ('.data_get($message, 'payload.subscription.status').')');
            }
        }
    }

    private function account(): TwitchAccount
    {
        $account = TwitchAccount::latest('updated_at')->first();

        if (! $account) {
            throw new RuntimeException('Nenhum canal conectado. Faça login no dashboard primeiro.');
        }

        return $this->tokens->ensureFresh($account);
    }

    private function connect(string $url): Client
    {
        $client = new Client($url);
        $client->addMiddleware(new CloseHandler)->addMiddleware(new PingResponder);
        $client->getContext()->setOption('ssl', 'cafile', CaBundle::getSystemCaRootBundlePath());
        $client->setTimeout($this->keepaliveSeconds + 5);
        $client->connect();

        return $client;
    }

    private function awaitWelcome(Client $client): string
    {
        $message = $this->receive($client);

        if (data_get($message, 'metadata.message_type') !== 'session_welcome') {
            throw new RuntimeException('A Twitch não enviou a mensagem de boas-vindas.');
        }

        $this->keepaliveSeconds = (int) data_get($message, 'payload.session.keepalive_timeout_seconds', 10);
        $client->setTimeout($this->keepaliveSeconds + 5);
        EventSubHandler::beat();

        return (string) data_get($message, 'payload.session.id');
    }

    /**
     * @return array<int, string> scopes que faltaram (inscrições puladas)
     */
    private function subscribe(TwitchAccount $account, string $sessionId): array
    {
        $skipped = [];

        foreach (EventSubHandler::subscriptions($account->twitch_id) as $sub) {
            if ($sub['scope'] && ! $account->hasScope($sub['scope'])) {
                $this->warn("Pulando {$sub['type']}: falta a permissão {$sub['scope']}. Autorize no dashboard.");
                $skipped[] = $sub['scope'];

                continue;
            }

            $status = $this->twitch->subscribeWebSocket(
                $account->access_token,
                $sessionId,
                $sub['type'],
                $sub['version'],
                $sub['condition'],
            );

            match (true) {
                $status === 202, $status === 409 => $this->info("Escutando {$sub['type']}"),
                default => $this->warn("Twitch recusou {$sub['type']} (HTTP {$status})"),
            };
        }

        return $skipped;
    }

    /**
     * Próxima mensagem de texto. Sem nada dentro do keepalive = conexão morta (a Twitch exige reconectar).
     *
     * @return array<string, mixed>
     */
    private function receive(Client $client): array
    {
        while (true) {
            try {
                $message = $client->receive();
            } catch (ConnectionTimeoutException) {
                throw new RuntimeException('sem resposta da Twitch dentro do keepalive');
            }

            if ($message->getOpcode() === 'close') {
                throw new RuntimeException('a Twitch fechou a conexão');
            }

            if ($message->getOpcode() === 'text') {
                return (array) json_decode($message->getContent(), true);
            }
        }
    }
}
