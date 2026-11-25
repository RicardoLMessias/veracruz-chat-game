<?php

namespace Tests\Feature;

use App\Console\Commands\SubscribeEventSub;
use App\Jobs\ProcessTwitchEvent;
use App\Models\AppSetting;
use App\Models\ChatterStreamStat;
use App\Models\EventLog;
use App\Models\TwitchAccount;
use App\Models\User;
use App\Services\Chat\ChatTracker;
use App\Services\Twitch\TwitchClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class EventSubWebhookTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'segredo-de-teste-123';

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.twitch' => [
            'client_id' => 'test-client',
            'client_secret' => 'test-secret',
            'redirect_uri' => 'http://localhost:5173/auth/twitch/callback',
            'scopes' => 'user:read:email user:read:chat moderator:read:chatters channel:read:redemptions bits:read user:bot channel:bot',
            'eventsub_secret' => self::SECRET,
        ]]);
        $this->app->forgetInstance(TwitchClient::class);
        Http::preventStrayRequests();
    }

    public function test_verification_returns_challenge(): void
    {
        $response = $this->webhook('webhook_callback_verification', ['challenge' => 'abc123', 'subscription' => ['type' => 'stream.online']]);

        $response->assertOk();
        $this->assertSame('abc123', $response->getContent());
        $this->assertStringStartsWith('text/plain', $response->headers->get('Content-Type'));
    }

    public function test_invalid_signature_is_rejected(): void
    {
        $this->webhook('notification', ['subscription' => ['type' => 'stream.offline'], 'event' => []], signature: 'sha256=errado')
            ->assertForbidden();

        $this->assertSame(0, EventLog::count());
    }

    public function test_old_message_is_rejected(): void
    {
        $this->webhook('notification', ['subscription' => ['type' => 'stream.offline'], 'event' => []], timestamp: now()->subMinutes(11)->toIso8601String())
            ->assertForbidden();
    }

    public function test_notification_is_logged_and_queued_once(): void
    {
        Queue::fake();
        $payload = ['subscription' => ['type' => 'stream.offline'], 'event' => []];

        $this->webhook('notification', $payload, messageId: 'dup-1')->assertNoContent();
        $this->webhook('notification', $payload, messageId: 'dup-1')->assertNoContent();

        $this->assertSame(1, EventLog::count());
        Queue::assertPushed(ProcessTwitchEvent::class, 1);
    }

    public function test_queued_event_updates_chat_stats(): void
    {
        app(ChatTracker::class)->openSession('s1', now());

        // Fila síncrona nos testes: o job roda na hora.
        $this->webhook('notification', [
            'subscription' => ['type' => 'channel.chat.message'],
            'event' => ['chatter_user_id' => '1', 'chatter_user_login' => 'alice', 'chatter_user_name' => 'Alice'],
        ])->assertNoContent();

        $this->assertSame(1, ChatterStreamStat::sole()->message_count);
        $this->assertNotNull(EventLog::sole()->processed_at);
    }

    public function test_tunnel_only_exposes_the_webhook(): void
    {
        $this->withHeader('CF-Ray', 'abc')->getJson('/api/health')->assertNotFound();
        $this->withHeader('CF-Ray', 'abc')->get('/auth/twitch/redirect')->assertNotFound();
        $this->flushHeaders()->getJson('/api/health')->assertOk();
    }

    public function test_subscribe_command_creates_webhook_subscriptions(): void
    {
        $this->account();
        Http::fake([
            TwitchClient::TOKEN_URL => Http::response(['access_token' => 'app-token', 'expires_in' => 5000000]),
            TwitchClient::HELIX_URL.'/eventsub/subscriptions*' => Http::sequence()
                ->push(['data' => [[
                    'id' => 'old', 'type' => 'stream.online', 'status' => 'enabled',
                    'transport' => ['method' => 'webhook', 'callback' => 'https://antigo.trycloudflare.com/twitch/eventsub'],
                ]], 'pagination' => []])
                ->whenEmpty(Http::response(['data' => []], 202)),
        ]);

        $this->artisan('twitch:subscribe', ['--url' => 'https://novo.trycloudflare.com/'])->assertSuccessful();

        $this->assertSame('https://novo.trycloudflare.com', AppSetting::getValue(SubscribeEventSub::CALLBACK_SETTING));
        Http::assertSent(fn ($r) => $r->method() === 'DELETE' && str_contains($r->url(), 'id=old'));
        Http::assertSent(fn ($r) => $r->method() === 'POST'
            && ($r['type'] ?? null) === 'channel.chat.message'
            && $r['transport']['callback'] === 'https://novo.trycloudflare.com/twitch/eventsub'
            && $r['transport']['secret'] === self::SECRET
            && $r->hasHeader('Authorization', 'Bearer app-token'));
        $this->assertSame([], Cache::get(SubscribeEventSub::STATUS_KEY)['problems']);
    }

    public function test_subscribe_skips_chat_without_bot_scopes(): void
    {
        $this->account(['user:read:email', 'user:read:chat', 'moderator:read:chatters', 'channel:read:redemptions', 'bits:read']);
        Http::fake([
            TwitchClient::TOKEN_URL => Http::response(['access_token' => 'app-token', 'expires_in' => 5000000]),
            TwitchClient::HELIX_URL.'/eventsub/subscriptions*' => Http::sequence()
                ->push(['data' => [], 'pagination' => []])
                ->whenEmpty(Http::response(['data' => []], 202)),
        ]);

        $this->artisan('twitch:subscribe', ['--url' => 'https://novo.trycloudflare.com'])->assertSuccessful();

        Http::assertNotSent(fn ($r) => $r->method() === 'POST' && ($r['type'] ?? null) === 'channel.chat.message');
        $this->assertContains('channel.chat.message', Cache::get(SubscribeEventSub::STATUS_KEY)['problems']);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function webhook(string $type, array $payload, ?string $messageId = null, ?string $timestamp = null, ?string $signature = null)
    {
        $messageId ??= 'msg-'.uniqid();
        $timestamp ??= now()->toIso8601String();
        $body = json_encode($payload);
        $signature ??= 'sha256='.hash_hmac('sha256', $messageId.$timestamp.$body, self::SECRET);

        return $this->call('POST', '/twitch/eventsub', [], [], [], [
            'HTTP_TWITCH_EVENTSUB_MESSAGE_ID' => $messageId,
            'HTTP_TWITCH_EVENTSUB_MESSAGE_TIMESTAMP' => $timestamp,
            'HTTP_TWITCH_EVENTSUB_MESSAGE_SIGNATURE' => $signature,
            'HTTP_TWITCH_EVENTSUB_MESSAGE_TYPE' => $type,
            'CONTENT_TYPE' => 'application/json',
        ], $body);
    }

    /**
     * @param  array<int, string>|null  $scopes
     */
    private function account(?array $scopes = null): TwitchAccount
    {
        return TwitchAccount::create([
            'user_id' => User::factory()->create()->id,
            'twitch_id' => '123',
            'login' => 'streamer',
            'display_name' => 'Streamer',
            'access_token' => 'access-1',
            'refresh_token' => 'refresh-1',
            'scopes' => $scopes ?? explode(' ', config('services.twitch.scopes')),
            'expires_at' => now()->addHours(3),
            'last_validated_at' => now(),
        ]);
    }
}
