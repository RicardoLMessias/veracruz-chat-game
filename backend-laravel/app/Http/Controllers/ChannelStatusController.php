<?php

namespace App\Http\Controllers;

use App\Models\TwitchAccount;
use App\Services\Twitch\TwitchAuthException;
use App\Services\Twitch\TwitchClient;
use App\Services\Twitch\TwitchTokenManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

class ChannelStatusController extends Controller
{
    /** Segundos que a resposta fica em cache; o front atualiza a cada 30s. */
    private const CACHE_SECONDS = 15;

    public function __invoke(Request $request, TwitchClient $twitch, TwitchTokenManager $tokens): JsonResponse
    {
        $account = $request->user()->twitchAccount;

        if (! $account) {
            return response()->json(['message' => 'Faça login com a Twitch novamente.', 'code' => 'twitch_reauth'], 401);
        }

        try {
            $status = Cache::remember(
                "twitch:status:{$account->twitch_id}",
                self::CACHE_SECONDS,
                fn () => $this->fetch($twitch, $tokens->ensureFresh($account)),
            );
        } catch (TwitchAuthException) {
            return response()->json(['message' => 'Faça login com a Twitch novamente.', 'code' => 'twitch_reauth'], 401);
        } catch (Throwable $e) {
            Log::warning('Falha ao buscar status da live', ['message' => $e->getMessage()]);

            return response()->json(['message' => 'Não foi possível falar com a Twitch agora.'], 503);
        }

        return response()->json($status);
    }

    /**
     * @return array<string, mixed>
     */
    private function fetch(TwitchClient $twitch, TwitchAccount $account): array
    {
        $token = $account->access_token;
        $stream = $twitch->stream($token, $account->twitch_id);
        $channel = $stream ? null : $twitch->channelInfo($token, $account->twitch_id);
        $source = $stream ?? $channel ?? [];

        return [
            'live' => $stream !== null,
            'title' => $source['title'] ?? null,
            'game_name' => $source['game_name'] ?? null,
            'tags' => array_values((array) ($source['tags'] ?? [])),
            'language' => $source['language'] ?? $source['broadcaster_language'] ?? null,
            'viewer_count' => $stream ? (int) $stream['viewer_count'] : null,
            'started_at' => $stream['started_at'] ?? null,
            'thumbnail_url' => $stream
                ? str_replace(['{width}', '{height}'], ['640', '360'], (string) $stream['thumbnail_url'])
                : null,
            'fetched_at' => now()->toIso8601String(),
        ];
    }
}
