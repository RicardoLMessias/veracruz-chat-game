<?php

namespace App\Http\Controllers;

use App\Models\AppSetting;
use App\Services\Twitch\TwitchClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Tela de configuração inicial: recebe o Client ID e o Secret da Twitch pelo próprio dashboard.
 */
class SetupController extends Controller
{
    public function status(TwitchClient $twitch): JsonResponse
    {
        // Rota pública: não devolve nada das credenciais, nem parte do Client ID.
        return response()->json([
            'twitch_configured' => $twitch->isConfigured(),
            'redirect_uri' => $twitch->redirectUri(),
            'console_url' => 'https://dev.twitch.tv/console/apps/create',
        ]);
    }

    public function saveTwitch(Request $request, TwitchClient $twitch): JsonResponse
    {
        // Depois de configurado, só quem está logado pode trocar as credenciais.
        if ($twitch->isConfigured() && ! $request->user()) {
            return response()->json(['message' => 'Faça login para alterar as credenciais.'], 403);
        }

        $data = $request->validate([
            'client_id' => ['required', 'string', 'alpha_num', 'max:64'],
            'client_secret' => ['required', 'string', 'alpha_num', 'max:64'],
        ], [
            'client_id.required' => 'Informe o Client ID.',
            'client_id.alpha_num' => 'O Client ID só tem letras e números. Confira se copiou certo.',
            'client_secret.required' => 'Informe o Client Secret.',
            'client_secret.alpha_num' => 'O Client Secret só tem letras e números. Confira se copiou certo.',
        ]);

        try {
            $valid = $twitch->credentialsAreValid($data['client_id'], $data['client_secret']);
        } catch (Throwable $e) {
            Log::warning('Não foi possível validar credenciais da Twitch', ['message' => $e->getMessage()]);

            return response()->json(['message' => 'Não foi possível falar com a Twitch agora. Tente em instantes.'], 503);
        }

        if (! $valid) {
            throw ValidationException::withMessages([
                'client_secret' => 'A Twitch não reconheceu esse Client ID + Secret. Gere um novo Secret no console e tente de novo.',
            ]);
        }

        AppSetting::setValue(TwitchClient::SETTING_CLIENT_ID, $data['client_id']);
        AppSetting::setValue(TwitchClient::SETTING_CLIENT_SECRET, $data['client_secret']);
        app()->forgetInstance(TwitchClient::class);

        return response()->json(['twitch_configured' => true]);
    }
}
