<?php

namespace App\Services\Twitch;

use App\Models\AppSetting;
use Composer\CaBundle\CaBundle;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Conversa com os endpoints de OAuth (id.twitch.tv) e da API Helix (api.twitch.tv).
 */
class TwitchClient
{
    public const AUTHORIZE_URL = 'https://id.twitch.tv/oauth2/authorize';

    public const TOKEN_URL = 'https://id.twitch.tv/oauth2/token';

    public const VALIDATE_URL = 'https://id.twitch.tv/oauth2/validate';

    public const REVOKE_URL = 'https://id.twitch.tv/oauth2/revoke';

    public const HELIX_URL = 'https://api.twitch.tv/helix';

    public function __construct(
        private readonly string $clientId,
        private readonly string $clientSecret,
        private readonly string $redirectUri,
        private readonly string $scopes,
    ) {}

    public const SETTING_CLIENT_ID = 'twitch.client_id';

    public const SETTING_CLIENT_SECRET = 'twitch.client_secret';

    /**
     * Credenciais salvas pela tela de configuração têm prioridade; o .env fica como alternativa.
     */
    public static function fromConfig(): self
    {
        $config = config('services.twitch');

        return new self(
            AppSetting::getValue(self::SETTING_CLIENT_ID) ?? (string) ($config['client_id'] ?? ''),
            AppSetting::getValue(self::SETTING_CLIENT_SECRET) ?? (string) ($config['client_secret'] ?? ''),
            (string) ($config['redirect_uri'] ?? ''),
            (string) ($config['scopes'] ?? ''),
        );
    }

    public function isConfigured(): bool
    {
        return $this->clientId !== '' && $this->clientSecret !== '' && $this->redirectUri !== '';
    }

    public function redirectUri(): string
    {
        return $this->redirectUri;
    }

    /**
     * Confere se o par Client ID + Secret existe na Twitch (pede um app token e descarta).
     * Retorna false para credenciais erradas; lança exceção se a Twitch estiver inacessível.
     */
    public function credentialsAreValid(string $clientId, string $clientSecret): bool
    {
        $response = $this->http()->asForm()->post(self::TOKEN_URL, [
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'grant_type' => 'client_credentials',
        ]);

        if ($response->clientError()) {
            return false;
        }

        return filled($response->throw()->json('access_token'));
    }

    public function authorizeUrl(string $state): string
    {
        return self::AUTHORIZE_URL.'?'.http_build_query([
            'client_id' => $this->clientId,
            'redirect_uri' => $this->redirectUri,
            'response_type' => 'code',
            'scope' => $this->scopes,
            'state' => $state,
        ], '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * Troca o code do callback por access_token + refresh_token.
     *
     * @return array{access_token: string, refresh_token: string, expires_in: int, scope: array<int, string>}
     */
    public function exchangeCode(string $code): array
    {
        $response = $this->http()->asForm()->post(self::TOKEN_URL, [
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
            'code' => $code,
            'grant_type' => 'authorization_code',
            'redirect_uri' => $this->redirectUri,
        ]);

        return $this->tokenPayload($response, 'Não foi possível trocar o código de autorização.');
    }

    /**
     * @return array{access_token: string, refresh_token: string, expires_in: int, scope: array<int, string>}
     */
    public function refresh(string $refreshToken): array
    {
        $response = $this->http()->asForm()->post(self::TOKEN_URL, [
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
            'grant_type' => 'refresh_token',
            'refresh_token' => $refreshToken,
        ]);

        return $this->tokenPayload($response, 'A Twitch recusou a renovação do token.');
    }

    /**
     * Validação exigida pela Twitch (no mínimo a cada hora). Retorna false se o token não vale mais.
     */
    public function validate(string $accessToken): bool
    {
        $response = $this->http()
            ->withHeaders(['Authorization' => 'OAuth '.$accessToken])
            ->get(self::VALIDATE_URL);

        if ($response->status() === 401) {
            return false;
        }

        $response->throw();

        return true;
    }

    public function revoke(string $accessToken): void
    {
        $this->http()->asForm()->post(self::REVOKE_URL, [
            'client_id' => $this->clientId,
            'token' => $accessToken,
        ]);
    }

    /**
     * Usuário dono do token (o seu canal).
     *
     * @return array<string, mixed>
     */
    public function currentUser(string $accessToken): array
    {
        $response = $this->helix($accessToken)->get(self::HELIX_URL.'/users');

        if ($response->status() === 401) {
            throw new TwitchAuthException('Token recusado pela API da Twitch.');
        }

        $user = $response->throw()->json('data.0');

        if (! is_array($user)) {
            throw new TwitchAuthException('A Twitch não retornou os dados do canal.');
        }

        return $user;
    }

    /**
     * Live em andamento do canal, ou null se estiver offline.
     *
     * @return array<string, mixed>|null
     */
    public function stream(string $accessToken, string $userId): ?array
    {
        $data = $this->helixGet($accessToken, '/streams', ['user_id' => $userId, 'type' => 'live']);

        return $data[0] ?? null;
    }

    /**
     * Perfis públicos (nome e foto) de até 100 usuários por chamada.
     *
     * @param  array<int, string>  $ids
     * @return array<int, array<string, mixed>>
     */
    public function users(string $accessToken, array $ids): array
    {
        $ids = array_slice(array_values(array_unique($ids)), 0, 100);

        if ($ids === []) {
            return [];
        }

        // A Twitch espera ?id=1&id=2 (sem colchetes), então a query é montada à mão.
        $query = implode('&', array_map(fn ($id) => 'id='.rawurlencode($id), $ids));
        $response = $this->helix($accessToken)->get(self::HELIX_URL.'/users?'.$query);

        if ($response->status() === 401) {
            throw new TwitchAuthException('Token recusado pela API da Twitch.');
        }

        return (array) $response->throw()->json('data', []);
    }

    /**
     * Todos os inscritos atuais do canal (scope channel:read:subscriptions; só afiliado/parceiro).
     * A Twitch não informa aqui se a sub é Prime.
     *
     * @return array<int, array<string, mixed>>
     */
    public function subscriptions(string $accessToken, string $broadcasterId): array
    {
        $all = [];
        $cursor = null;

        do {
            $response = $this->helix($accessToken)->get(self::HELIX_URL.'/subscriptions', array_filter([
                'broadcaster_id' => $broadcasterId,
                'first' => 100,
                'after' => $cursor,
            ]));

            if ($response->status() === 401) {
                throw new TwitchAuthException('Token recusado pela API da Twitch.');
            }

            $response->throw();
            array_push($all, ...(array) $response->json('data', []));
            $cursor = $response->json('pagination.cursor');
        } while ($cursor && count($all) < 20000);

        return $all;
    }

    /**
     * Título, categoria e tags configurados no canal (valem mesmo com a live desligada).
     *
     * @return array<string, mixed>|null
     */
    public function channelInfo(string $accessToken, string $broadcasterId): ?array
    {
        $data = $this->helixGet($accessToken, '/channels', ['broadcaster_id' => $broadcasterId]);

        return $data[0] ?? null;
    }

    /**
     * Quem está no chat agora (só contas logadas; a Twitch atualiza essa lista com alguns minutos de atraso).
     * Exige o scope moderator:read:chatters; o próprio dono do canal conta como moderador.
     *
     * @return array<int, array{user_id: string, user_login: string, user_name: string}>
     */
    public function chatters(string $accessToken, string $broadcasterId): array
    {
        $all = [];
        $cursor = null;

        do {
            $response = $this->helix($accessToken)->get(self::HELIX_URL.'/chat/chatters', array_filter([
                'broadcaster_id' => $broadcasterId,
                'moderator_id' => $broadcasterId,
                'first' => 1000,
                'after' => $cursor,
            ]));

            if ($response->status() === 401) {
                throw new TwitchAuthException('Token recusado pela API da Twitch.');
            }

            $response->throw();
            array_push($all, ...(array) $response->json('data', []));
            $cursor = $response->json('pagination.cursor');
        } while ($cursor && count($all) < 10000);

        return $all;
    }

    /**
     * Cria uma inscrição do EventSub ligada a uma sessão WebSocket.
     *
     * @param  array<string, string>  $condition
     * @return int status HTTP (202 criado, 409 já existia, 403 falta permissão)
     */
    public function subscribeWebSocket(string $accessToken, string $sessionId, string $type, string $version, array $condition): int
    {
        $response = $this->helix($accessToken)->post(self::HELIX_URL.'/eventsub/subscriptions', [
            'type' => $type,
            'version' => $version,
            'condition' => $condition,
            'transport' => ['method' => 'websocket', 'session_id' => $sessionId],
        ]);

        if ($response->status() === 401) {
            throw new TwitchAuthException('Token recusado pela API da Twitch.');
        }

        if ($response->serverError()) {
            $response->throw();
        }

        return $response->status();
    }

    /**
     * App Access Token (client credentials). Usado para as inscrições por webhook.
     * Fica em cache até perto de expirar.
     */
    public function appAccessToken(bool $forceNew = false): string
    {
        $key = 'twitch:app_token:'.md5($this->clientId);

        if ($forceNew) {
            Cache::forget($key);
        }

        $cached = Cache::get($key);

        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        $response = $this->http()->asForm()->post(self::TOKEN_URL, [
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
            'grant_type' => 'client_credentials',
        ]);

        if ($response->clientError()) {
            throw new TwitchAuthException('A Twitch recusou o Client ID/Secret do app.');
        }

        $token = (string) $response->throw()->json('access_token');
        $ttl = max(60, (int) $response->json('expires_in', 3600) - 300);
        Cache::put($key, $token, now()->addSeconds($ttl));

        return $token;
    }

    /**
     * Inscrições existentes deste app (todas as páginas).
     *
     * @return array<int, array<string, mixed>>
     */
    public function listSubscriptions(): array
    {
        $all = [];
        $cursor = null;

        do {
            $response = $this->helix($this->appAccessToken())
                ->get(self::HELIX_URL.'/eventsub/subscriptions', array_filter(['after' => $cursor]));

            $response->throw();
            array_push($all, ...(array) $response->json('data', []));
            $cursor = $response->json('pagination.cursor');
        } while ($cursor);

        return $all;
    }

    public function deleteSubscription(string $id): void
    {
        $this->helix($this->appAccessToken())
            ->delete(self::HELIX_URL.'/eventsub/subscriptions?id='.urlencode($id));
    }

    /**
     * Cria uma inscrição por webhook. Retorna o status HTTP e a mensagem de erro da Twitch, se houver.
     *
     * @param  array<string, string>  $condition
     * @return array{status: int, message: string|null}
     */
    public function subscribeWebhook(string $type, string $version, array $condition, string $callback, string $secret): array
    {
        $response = $this->helix($this->appAccessToken())->post(self::HELIX_URL.'/eventsub/subscriptions', [
            'type' => $type,
            'version' => $version,
            'condition' => $condition,
            'transport' => ['method' => 'webhook', 'callback' => $callback, 'secret' => $secret],
        ]);

        if ($response->serverError()) {
            $response->throw();
        }

        return ['status' => $response->status(), 'message' => $response->json('message')];
    }

    /**
     * @param  array<string, string>  $query
     * @return array<int, array<string, mixed>>
     */
    private function helixGet(string $accessToken, string $path, array $query): array
    {
        $response = $this->helix($accessToken)->get(self::HELIX_URL.$path, $query);

        if ($response->status() === 401) {
            throw new TwitchAuthException('Token recusado pela API da Twitch.');
        }

        return (array) $response->throw()->json('data', []);
    }

    public function helix(string $accessToken): PendingRequest
    {
        return $this->http()->withToken($accessToken)->withHeaders(['Client-Id' => $this->clientId]);
    }

    private function http(): PendingRequest
    {
        // Repete só em falha de rede ou erro 5xx; erros 4xx (code inválido, token revogado) voltam na hora.
        // No Windows o PHP costuma vir sem lista de certificados (cURL error 60);
        // o CaBundle usa a do sistema quando existe e, se não, a lista da Mozilla embutida no pacote.
        return Http::acceptJson()->timeout(10)->withOptions([
            'verify' => CaBundle::getSystemCaRootBundlePath(),
        ])->retry(
            2,
            200,
            fn (Throwable $e) => $e instanceof ConnectionException
                || ($e instanceof RequestException && $e->response->serverError()),
            throw: false,
        );
    }

    /**
     * @return array{access_token: string, refresh_token: string, expires_in: int, scope: array<int, string>}
     */
    private function tokenPayload(Response $response, string $message): array
    {
        if ($response->clientError()) {
            throw new TwitchAuthException($message);
        }

        $data = $response->throw()->json();

        if (empty($data['access_token']) || empty($data['refresh_token'])) {
            throw new TwitchAuthException($message);
        }

        return [
            'access_token' => $data['access_token'],
            'refresh_token' => $data['refresh_token'],
            'expires_in' => (int) ($data['expires_in'] ?? 3600),
            'scope' => (array) ($data['scope'] ?? []),
        ];
    }
}
