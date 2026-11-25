<?php

namespace Tests\Feature;

use App\Models\TwitchAccount;
use App\Models\User;
use App\Services\Twitch\TwitchClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TwitchAuthTest extends TestCase
{
    use RefreshDatabase;

    private const FRONTEND = 'http://localhost:5173';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'app.frontend_url' => self::FRONTEND,
            'services.twitch' => [
                'client_id' => 'test-client',
                'client_secret' => 'test-secret',
                'redirect_uri' => self::FRONTEND.'/auth/twitch/callback',
                'scopes' => 'user:read:email',
            ],
        ]);
        $this->app->forgetInstance(TwitchClient::class);
        Http::preventStrayRequests();
    }

    public function test_redirect_sends_to_twitch_with_state(): void
    {
        $response = $this->get('/auth/twitch/redirect');

        $location = $response->headers->get('Location');
        $this->assertStringStartsWith(TwitchClient::AUTHORIZE_URL.'?', $location);
        parse_str(parse_url($location, PHP_URL_QUERY), $query);

        $this->assertSame('test-client', $query['client_id']);
        $this->assertSame('code', $query['response_type']);
        $this->assertSame(self::FRONTEND.'/auth/twitch/callback', $query['redirect_uri']);
        $this->assertSame(session('twitch_oauth_state'), $query['state']);
    }

    public function test_redirect_without_credentials_returns_error_to_frontend(): void
    {
        config(['services.twitch.client_id' => '']);
        $this->app->forgetInstance(TwitchClient::class);

        $this->get('/auth/twitch/redirect')
            ->assertRedirect(self::FRONTEND.'/?auth_error=not_configured');
    }

    public function test_callback_rejects_wrong_state(): void
    {
        $this->withSession(['twitch_oauth_state' => 'expected'])
            ->get('/auth/twitch/callback?code=abc&state=forged')
            ->assertRedirect(self::FRONTEND.'/?auth_error=invalid_state');

        $this->assertGuest();
    }

    public function test_callback_when_user_denies(): void
    {
        $this->withSession(['twitch_oauth_state' => 'expected'])
            ->get('/auth/twitch/callback?error=access_denied&state=expected')
            ->assertRedirect(self::FRONTEND.'/?auth_error=denied');
    }

    public function test_callback_logs_in_and_stores_encrypted_tokens(): void
    {
        $this->fakeTwitch();

        $this->withSession(['twitch_oauth_state' => 'expected'])
            ->get('/auth/twitch/callback?code=good-code&state=expected')
            ->assertRedirect(self::FRONTEND);

        $account = TwitchAccount::firstOrFail();
        $this->assertSame('123', $account->twitch_id);
        $this->assertSame('Streamer', $account->display_name);
        $this->assertSame('access-1', $account->access_token);
        $this->assertSame('refresh-1', $account->refresh_token);
        $this->assertNotSame('access-1', DB::table('twitch_accounts')->value('access_token'));
        $this->assertAuthenticatedAs($account->user);

        Http::assertSent(fn ($request) => $request->url() === TwitchClient::TOKEN_URL
            && $request['code'] === 'good-code'
            && $request['grant_type'] === 'authorization_code');
    }

    public function test_second_login_reuses_same_user(): void
    {
        $this->fakeTwitch();

        foreach (['a', 'b'] as $state) {
            $this->withSession(['twitch_oauth_state' => $state])
                ->get("/auth/twitch/callback?code=c&state={$state}");
        }

        $this->assertSame(1, User::count());
        $this->assertSame(1, TwitchAccount::count());
    }

    public function test_me_requires_login(): void
    {
        $this->getJson('/api/me')->assertUnauthorized();
    }

    public function test_me_returns_channel(): void
    {
        $account = $this->account();

        $this->actingAs($account->user)
            ->getJson('/api/me')
            ->assertOk()
            ->assertJsonPath('channel.login', 'streamer')
            ->assertJsonMissingPath('channel.access_token');
    }

    public function test_me_refreshes_expired_token(): void
    {
        $account = $this->account(['expires_at' => now()->subMinute()]);
        Http::fake([
            TwitchClient::TOKEN_URL => Http::response([
                'access_token' => 'access-2',
                'refresh_token' => 'refresh-2',
                'expires_in' => 14000,
                'scope' => ['user:read:email'],
            ]),
        ]);

        $this->actingAs($account->user)->getJson('/api/me')->assertOk();

        $account->refresh();
        $this->assertSame('access-2', $account->access_token);
        $this->assertSame('refresh-2', $account->refresh_token);
        $this->assertTrue($account->expires_at->isFuture());
    }

    public function test_me_asks_for_new_login_when_refresh_is_rejected(): void
    {
        $account = $this->account(['expires_at' => now()->subMinute()]);
        Http::fake([TwitchClient::TOKEN_URL => Http::response(['message' => 'Invalid refresh token'], 400)]);

        $this->actingAs($account->user)
            ->getJson('/api/me')
            ->assertUnauthorized()
            ->assertJsonPath('code', 'twitch_reauth');
    }

    public function test_me_validates_token_hourly(): void
    {
        $account = $this->account(['last_validated_at' => now()->subHours(2)]);
        Http::fake([TwitchClient::VALIDATE_URL => Http::response(['client_id' => 'test-client'])]);

        $this->actingAs($account->user)->getJson('/api/me')->assertOk();

        $this->assertTrue($account->fresh()->last_validated_at->isAfter(now()->subMinute()));
    }

    public function test_logout_revokes_token_and_ends_session(): void
    {
        $account = $this->account();
        Http::fake([TwitchClient::REVOKE_URL => Http::response()]);

        $this->actingAs($account->user)
            ->withHeader('Origin', self::FRONTEND)
            ->postJson('/api/auth/logout')
            ->assertOk();

        $this->assertGuest('web');
        Http::assertSent(fn ($request) => $request->url() === TwitchClient::REVOKE_URL
            && $request['token'] === 'access-1');
    }

    private function fakeTwitch(): void
    {
        Http::fake([
            TwitchClient::TOKEN_URL => Http::response([
                'access_token' => 'access-1',
                'refresh_token' => 'refresh-1',
                'expires_in' => 14000,
                'scope' => ['user:read:email'],
            ]),
            TwitchClient::HELIX_URL.'/users' => Http::response(['data' => [[
                'id' => '123',
                'login' => 'streamer',
                'display_name' => 'Streamer',
                'email' => 'streamer@example.com',
                'profile_image_url' => 'https://example.com/a.png',
                'broadcaster_type' => 'affiliate',
            ]]]),
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function account(array $overrides = []): TwitchAccount
    {
        $user = User::factory()->create();

        return TwitchAccount::create(array_merge([
            'user_id' => $user->id,
            'twitch_id' => '123',
            'login' => 'streamer',
            'display_name' => 'Streamer',
            'access_token' => 'access-1',
            'refresh_token' => 'refresh-1',
            'scopes' => ['user:read:email'],
            'expires_at' => now()->addHours(3),
            'last_validated_at' => now(),
        ], $overrides));
    }
}
