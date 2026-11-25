<?php

namespace App\Services\Twitch;

use App\Models\TwitchAccount;

/**
 * Mantém os tokens de uma conta válidos: renova antes de expirar e valida a cada hora,
 * como a Twitch exige de apps que usam tokens de usuário.
 */
class TwitchTokenManager
{
    public function __construct(private readonly TwitchClient $twitch) {}

    /**
     * Garante um access_token utilizável. Lança TwitchAuthException se a conta precisar logar de novo.
     */
    public function ensureFresh(TwitchAccount $account): TwitchAccount
    {
        if ($account->tokenExpiresSoon()) {
            return $this->refresh($account);
        }

        if ($account->needsValidation()) {
            if (! $this->twitch->validate($account->access_token)) {
                return $this->refresh($account);
            }

            $account->forceFill(['last_validated_at' => now()])->save();
        }

        return $account;
    }

    public function refresh(TwitchAccount $account): TwitchAccount
    {
        $tokens = $this->twitch->refresh($account->refresh_token);

        $account->forceFill([
            'access_token' => $tokens['access_token'],
            'refresh_token' => $tokens['refresh_token'],
            'scopes' => $tokens['scope'],
            'expires_at' => now()->addSeconds($tokens['expires_in']),
            'last_validated_at' => now(),
        ])->save();

        return $account;
    }
}
