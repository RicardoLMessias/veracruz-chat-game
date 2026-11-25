<?php

namespace Tests\Feature;

use App\Models\TwitchAccount;
use App\Models\User;
use App\Services\Twitch\TwitchClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ChannelStatusTest extends TestCase
{
    use RefreshDatabase;

    private TwitchAccount $account;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.twitch' => [
            'client_id' => 'test-client',
            'client_secret' => 'test-secret',
            'redirect_uri' => 'http://localhost:5173/auth/twitch/callback',
            'scopes' => 'user:read:email',
        ]]);
        $this->app->forgetInstance(TwitchClient::class);
        Http::preventStrayRequests();

        $this->account = TwitchAccount::create([
            'user_id' => User::factory()->create()->id,
            'twitch_id' => '123',
            'login' => 'streamer',
            'display_name' => 'Streamer',
            'access_token' => 'access-1',
            'refresh_token' => 'refresh-1',
            'scopes' => ['user:read:email'],
            'expires_at' => now()->addHours(3),
            'last_validated_at' => now(),
        ]);
    }

    public function test_requires_login(): void
    {
        $this->getJson('/api/channel/status')->assertUnauthorized();
    }

    public function test_live_channel(): void
    {
        Http::fake([
            TwitchClient::HELIX_URL.'/streams*' => Http::response(['data' => [[
                'title' => 'Jogando com o chat',
                'game_name' => 'Minecraft',
                'viewer_count' => 42,
                'started_at' => '2026-11-22T01:00:00Z',
                'thumbnail_url' => 'https://static-cdn.jtvnw.net/previews-ttv/live_user_streamer-{width}x{height}.jpg',
                'tags' => ['Português'],
                'language' => 'pt',
            ]]]),
        ]);

        $this->actingAs($this->account->user)
            ->getJson('/api/channel/status')
            ->assertOk()
            ->assertJson([
                'live' => true,
                'title' => 'Jogando com o chat',
                'game_name' => 'Minecraft',
                'viewer_count' => 42,
                'started_at' => '2026-11-22T01:00:00Z',
                'thumbnail_url' => 'https://static-cdn.jtvnw.net/previews-ttv/live_user_streamer-640x360.jpg',
                'tags' => ['Português'],
            ]);

        Http::assertSent(fn ($request) => str_contains($request->url(), 'user_id=123')
            && $request->hasHeader('Client-Id', 'test-client'));
        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/channels'));
    }

    public function test_offline_channel_uses_channel_info(): void
    {
        Http::fake([
            TwitchClient::HELIX_URL.'/streams*' => Http::response(['data' => []]),
            TwitchClient::HELIX_URL.'/channels*' => Http::response(['data' => [[
                'title' => 'Próxima live sábado',
                'game_name' => 'Just Chatting',
                'broadcaster_language' => 'pt',
                'tags' => [],
            ]]]),
        ]);

        $this->actingAs($this->account->user)
            ->getJson('/api/channel/status')
            ->assertOk()
            ->assertJson([
                'live' => false,
                'title' => 'Próxima live sábado',
                'game_name' => 'Just Chatting',
                'language' => 'pt',
                'viewer_count' => null,
                'started_at' => null,
                'thumbnail_url' => null,
            ]);
    }

    public function test_response_is_cached_briefly(): void
    {
        Http::fake([
            TwitchClient::HELIX_URL.'/streams*' => Http::response(['data' => []]),
            TwitchClient::HELIX_URL.'/channels*' => Http::response(['data' => [['title' => 'x']]]),
        ]);

        $this->actingAs($this->account->user);
        $this->getJson('/api/channel/status')->assertOk();
        $this->getJson('/api/channel/status')->assertOk();

        Http::assertSentCount(2);
    }

    public function test_revoked_token_asks_for_login(): void
    {
        Http::fake([TwitchClient::HELIX_URL.'/streams*' => Http::response(['message' => 'Invalid OAuth token'], 401)]);

        $this->actingAs($this->account->user)
            ->getJson('/api/channel/status')
            ->assertUnauthorized()
            ->assertJsonPath('code', 'twitch_reauth');
    }

    public function test_twitch_outage_returns_503(): void
    {
        Http::fake([TwitchClient::HELIX_URL.'/streams*' => Http::response('', 500)]);

        $this->actingAs($this->account->user)
            ->getJson('/api/channel/status')
            ->assertStatus(503);
    }
}
