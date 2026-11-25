<?php

namespace App\Http\Controllers;

use App\Services\Twitch\TwitchAuthException;
use App\Services\Twitch\TwitchTokenManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Throwable;

class MeController extends Controller
{
    /**
     * Dados do usuário logado e do canal. Aproveita para validar/renovar o token da Twitch.
     */
    public function __invoke(Request $request, TwitchTokenManager $tokens): JsonResponse
    {
        $user = $request->user();
        $account = $user->twitchAccount;

        if (! $account) {
            return $this->reauth($request);
        }

        try {
            $account = $tokens->ensureFresh($account);
        } catch (TwitchAuthException $e) {
            Log::info('Token da Twitch não renova mais; pedindo novo login', ['twitch_id' => $account->twitch_id]);

            return $this->reauth($request);
        } catch (Throwable $e) {
            // Twitch fora do ar: mantém a sessão e devolve os dados salvos.
            Log::warning('Não foi possível validar o token da Twitch agora', ['message' => $e->getMessage()]);
        }

        return response()->json([
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
            ],
            'channel' => [
                'id' => $account->twitch_id,
                'login' => $account->login,
                'display_name' => $account->display_name,
                'profile_image_url' => $account->profile_image_url,
                'broadcaster_type' => $account->broadcaster_type,
                'scopes' => $account->scopes,
                // Permissões novas que o app pede e que ainda não foram autorizadas (precisa logar de novo).
                'missing_scopes' => $account->missingScopes(),
                'token_expires_at' => $account->expires_at->toIso8601String(),
            ],
        ]);
    }

    private function reauth(Request $request): JsonResponse
    {
        Auth::guard('web')->logout();

        if ($request->hasSession()) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return response()->json(['message' => 'Faça login com a Twitch novamente.', 'code' => 'twitch_reauth'], 401);
    }
}
