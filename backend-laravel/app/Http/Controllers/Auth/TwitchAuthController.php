<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\TwitchAccount;
use App\Models\User;
use App\Services\Twitch\TwitchAuthException;
use App\Services\Twitch\TwitchClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class TwitchAuthController extends Controller
{
    private const STATE_KEY = 'twitch_oauth_state';

    public function __construct(private readonly TwitchClient $twitch) {}

    /**
     * Passo 1: manda o navegador para a tela de autorização da Twitch.
     */
    public function redirect(Request $request): RedirectResponse
    {
        if (! $this->twitch->isConfigured()) {
            return $this->toFrontend('not_configured');
        }

        $state = Str::random(40);
        $request->session()->put(self::STATE_KEY, $state);

        return redirect()->away($this->twitch->authorizeUrl($state));
    }

    /**
     * Passo 2: a Twitch volta para cá com ?code=...&state=... (ou ?error=... se você negou).
     */
    public function callback(Request $request): RedirectResponse
    {
        $expectedState = $request->session()->pull(self::STATE_KEY);

        if ($request->filled('error')) {
            return $this->toFrontend($request->query('error') === 'access_denied' ? 'denied' : 'twitch_error');
        }

        $state = (string) $request->query('state', '');

        if (! is_string($expectedState) || $state === '' || ! hash_equals($expectedState, $state)) {
            return $this->toFrontend('invalid_state');
        }

        $code = (string) $request->query('code', '');

        if ($code === '') {
            return $this->toFrontend('twitch_error');
        }

        try {
            $tokens = $this->twitch->exchangeCode($code);
            $profile = $this->twitch->currentUser($tokens['access_token']);
        } catch (TwitchAuthException $e) {
            Log::warning('Twitch OAuth falhou', ['message' => $e->getMessage()]);

            return $this->toFrontend('twitch_error');
        } catch (Throwable $e) {
            Log::error('Twitch OAuth indisponível', ['message' => $e->getMessage()]);

            return $this->toFrontend('twitch_unavailable');
        }

        $user = DB::transaction(fn () => $this->storeAccount($profile, $tokens));

        Auth::login($user, remember: true);
        $request->session()->regenerate();

        return redirect()->away(config('app.frontend_url'));
    }

    public function logout(Request $request): JsonResponse
    {
        $account = $request->user()?->twitchAccount;

        if ($account) {
            try {
                $this->twitch->revoke($account->access_token);
            } catch (Throwable) {
                // Revogar é cortesia; se a Twitch estiver fora, o logout local segue mesmo assim.
            }
        }

        Auth::guard('web')->logout();

        if ($request->hasSession()) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return response()->json(['ok' => true]);
    }

    /**
     * @param  array<string, mixed>  $profile
     * @param  array{access_token: string, refresh_token: string, expires_in: int, scope: array<int, string>}  $tokens
     */
    private function storeAccount(array $profile, array $tokens): User
    {
        $twitchId = (string) $profile['id'];
        $email = $profile['email'] ?? null;

        $account = TwitchAccount::where('twitch_id', $twitchId)->first();
        $user = $account?->user;

        if (! $user) {
            $user = User::firstOrCreate(
                ['email' => $email ?: "{$twitchId}@twitch.local"],
                ['name' => $profile['display_name'], 'password' => Str::random(64)],
            );
        }

        $user->forceFill(['name' => $profile['display_name']])->save();

        TwitchAccount::updateOrCreate(
            ['twitch_id' => $twitchId],
            [
                'user_id' => $user->id,
                'login' => $profile['login'],
                'display_name' => $profile['display_name'],
                'email' => $email,
                'profile_image_url' => $profile['profile_image_url'] ?? null,
                'broadcaster_type' => $profile['broadcaster_type'] ?? null,
                'access_token' => $tokens['access_token'],
                'refresh_token' => $tokens['refresh_token'],
                'scopes' => $tokens['scope'],
                'expires_at' => now()->addSeconds($tokens['expires_in']),
                'last_validated_at' => now(),
            ],
        );

        return $user;
    }

    private function toFrontend(string $error): RedirectResponse
    {
        return redirect()->away(config('app.frontend_url').'/?auth_error='.$error);
    }
}
