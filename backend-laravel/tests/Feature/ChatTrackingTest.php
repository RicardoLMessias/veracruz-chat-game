<?php

namespace Tests\Feature;

use App\Models\ChannelEvent;
use App\Models\Chatter;
use App\Models\ChatterStreamStat;
use App\Models\StreamSession;
use App\Models\TwitchAccount;
use App\Models\User;
use App\Services\Chat\ChatTracker;
use App\Services\Twitch\EventSubHandler;
use App\Services\Twitch\TwitchClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ChatTrackingTest extends TestCase
{
    use RefreshDatabase;

    private ChatTracker $tracker;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.twitch' => [
            'client_id' => 'test-client',
            'client_secret' => 'test-secret',
            'redirect_uri' => 'http://localhost:5173/auth/twitch/callback',
            'scopes' => 'user:read:email user:read:chat moderator:read:chatters channel:read:redemptions',
        ]]);
        $this->app->forgetInstance(TwitchClient::class);
        Http::preventStrayRequests();
        Carbon::setTestNow('2026-11-22 20:00:00');

        $this->tracker = app(ChatTracker::class);
    }

    public function test_presence_accumulates_between_checks(): void
    {
        $session = $this->tracker->openSession('s1', now());
        $alice = [['user_id' => '1', 'user_login' => 'alice', 'user_name' => 'Alice']];

        $this->tracker->recordPresence($session, $alice, now());
        $this->tracker->recordPresence($session, $alice, now()->addSeconds(15));
        $this->tracker->recordPresence($session, $alice, now()->addSeconds(30));

        $stat = ChatterStreamStat::sole();
        $this->assertSame(30, $stat->watch_seconds);
        $this->assertTrue($stat->first_seen_at->equalTo(now()));
    }

    public function test_leaving_pauses_time_and_returning_resumes_from_previous_total(): void
    {
        $session = $this->tracker->openSession('s1', now());
        $alice = [['user_id' => '1', 'user_login' => 'alice', 'user_name' => 'Alice']];
        $bob = [['user_id' => '2', 'user_login' => 'bob', 'user_name' => 'Bob']];

        // Alice fica 0s → 60s, sai por ~4 minutos (só o Bob na lista) e volta 300s → 360s.
        foreach ([0, 15, 30, 45, 60] as $s) {
            $this->tracker->recordPresence($session, [...$alice, ...$bob], now()->addSeconds($s));
        }
        for ($s = 75; $s <= 285; $s += 15) {
            $this->tracker->recordPresence($session, $bob, now()->addSeconds($s));
        }
        foreach ([300, 315, 330, 345, 360] as $s) {
            $this->tracker->recordPresence($session, [...$alice, ...$bob], now()->addSeconds($s));
        }

        $aliceStat = ChatterStreamStat::whereHas('chatter', fn ($q) => $q->where('login', 'alice'))->sole();
        $bobStat = ChatterStreamStat::whereHas('chatter', fn ($q) => $q->where('login', 'bob'))->sole();

        // 60s antes de sair + 60s depois de voltar; os ~4 minutos fora não contam.
        $this->assertSame(120, $aliceStat->watch_seconds);
        $this->assertSame([[0, 60], [300, null]], $this->periods($aliceStat));

        // Bob ficou o tempo todo: 360s num período só.
        $this->assertSame(360, $bobStat->watch_seconds);
        $this->assertSame([[0, null]], $this->periods($bobStat));
    }

    public function test_bots_and_broadcaster_are_not_counted(): void
    {
        $account = $this->account(); // login "streamer"
        $session = $this->tracker->openSession('s1', now());

        $this->tracker->recordPresence($session, [
            ['user_id' => '1', 'user_login' => 'alice', 'user_name' => 'Alice'],
            ['user_id' => '50', 'user_login' => 'StreamElements', 'user_name' => 'StreamElements'],
            ['user_id' => '123', 'user_login' => 'streamer', 'user_name' => 'Streamer'],
        ], now());
        $this->tracker->recordMessage('50', 'streamelements', 'StreamElements');
        $this->tracker->recordMessage('123', 'streamer', 'Streamer');

        $this->assertSame(['alice'], ChatterStreamStat::with('chatter')->get()->pluck('chatter.login')->all());

        $this->actingAs($account->user)
            ->getJson('/api/chat/overview')
            ->assertJsonPath('totals.chatters', 1);
    }

    public function test_broadcaster_counts_when_enabled(): void
    {
        config(['services.twitch.count_broadcaster' => true]);
        $this->account();
        $tracker = app(ChatTracker::class);
        $session = $tracker->openSession('s1', now());

        $tracker->recordPresence($session, [['user_id' => '123', 'user_login' => 'streamer', 'user_name' => 'Streamer']], now());

        $this->assertSame(1, ChatterStreamStat::count());
    }

    public function test_session_end_closes_open_periods(): void
    {
        $session = $this->tracker->openSession('s1', now());
        $this->tracker->recordPresence($session, [['user_id' => '1', 'user_login' => 'alice', 'user_name' => 'Alice']], now());
        $this->tracker->recordPresence($session, [['user_id' => '1', 'user_login' => 'alice', 'user_name' => 'Alice']], now()->addSeconds(15));

        $this->tracker->closeOpenSessions();

        $this->assertSame([[0, 15]], $this->periods(ChatterStreamStat::sole()));
    }

    /**
     * Períodos como [segundos do início, segundos do fim | null se aberto], relativos ao "agora" do teste.
     *
     * @return array<int, array{0: int, 1: int|null}>
     */
    private function periods(ChatterStreamStat $stat): array
    {
        return $stat->segments()->get()->map(fn ($p) => [
            (int) now()->diffInSeconds($p->started_at),
            $p->ended_at ? (int) now()->diffInSeconds($p->ended_at) : null,
        ])->all();
    }

    public function test_gap_longer_than_tolerance_does_not_count(): void
    {
        $session = $this->tracker->openSession('s1', now());
        $alice = [['user_id' => '1', 'user_login' => 'alice', 'user_name' => 'Alice']];

        $this->tracker->recordPresence($session, $alice, now());
        $this->tracker->recordPresence($session, $alice, now()->addMinutes(10));

        $this->assertSame(0, ChatterStreamStat::sole()->watch_seconds);
    }

    public function test_fifteen_second_checks_are_exact_and_bridge_a_missed_check(): void
    {
        $session = $this->tracker->openSession('s1', now());
        $alice = [['user_id' => '1', 'user_login' => 'alice', 'user_name' => 'Alice']];

        // Vista em 0s, 15s, 30s; some da lista em 45s (oscilação da Twitch); volta em 60s e 75s.
        foreach ([0, 15, 30, 60, 75] as $second) {
            $this->tracker->recordPresence($session, $alice, now()->addSeconds($second));
        }

        $this->assertSame(75, ChatterStreamStat::sole()->watch_seconds);
    }

    public function test_message_between_checks_counts_as_presence(): void
    {
        $session = $this->tracker->openSession('s1', now());
        $alice = [['user_id' => '1', 'user_login' => 'alice', 'user_name' => 'Alice']];

        $this->tracker->recordPresence($session, $alice, now());
        $this->tracker->recordMessage('1', 'alice', 'Alice', now()->addSeconds(7));
        $this->tracker->recordPresence($session, $alice, now()->addSeconds(15));

        $this->assertSame(15, ChatterStreamStat::sole()->watch_seconds);
    }

    public function test_messages_are_counted_only_during_live(): void
    {
        $this->tracker->recordMessage('1', 'alice', 'Alice');
        $this->assertSame(0, ChatterStreamStat::count());

        $this->tracker->openSession('s1', now());
        $this->tracker->recordMessage('1', 'alice', 'Alice');
        $this->tracker->recordMessage('1', 'alice', 'Alice', now()->addSeconds(30));

        $stat = ChatterStreamStat::sole();
        $this->assertSame(2, $stat->message_count);
        $this->assertSame(30, $stat->watch_seconds);
    }

    public function test_new_stream_closes_previous_session(): void
    {
        $this->tracker->openSession('s1', now()->subHours(3));
        $this->tracker->openSession('s2', now());

        $this->assertNotNull(StreamSession::where('twitch_stream_id', 's1')->value('ended_at'));
        $this->assertSame('s2', StreamSession::current()->twitch_stream_id);
    }

    public function test_eventsub_handler_records_chat_and_redemption_once(): void
    {
        $this->tracker->openSession('s1', now());
        $handler = app(EventSubHandler::class);

        $chat = $this->notification('m1', 'channel.chat.message', [
            'chatter_user_id' => '1',
            'chatter_user_login' => 'alice',
            'chatter_user_name' => 'Alice',
            'message' => ['text' => 'oi'],
        ]);
        $handler->handleNotification($chat);
        $handler->handleNotification($chat); // reenvio da Twitch: não conta duas vezes

        $handler->handleNotification($this->notification('m2', 'channel.channel_points_custom_reward_redemption.add', [
            'id' => 'r1',
            'user_id' => '2',
            'user_login' => 'bob',
            'user_name' => 'Bob',
            'user_input' => 'toca aquela',
            'status' => 'unfulfilled',
            'reward' => ['id' => 'x', 'title' => 'Pedir música', 'cost' => 500],
            'redeemed_at' => '2026-11-22T20:00:05Z',
        ]));

        $this->assertSame(1, ChatterStreamStat::where('chatter_id', Chatter::where('login', 'alice')->value('id'))->value('message_count'));

        $event = ChannelEvent::sole();
        $this->assertSame('Pedir música', $event->title);
        $this->assertSame(500, $event->cost);
        $this->assertSame('toca aquela', $event->user_input);
        $this->assertSame(StreamSession::current()->id, $event->stream_session_id);
    }

    public function test_eventsub_stream_online_and_offline(): void
    {
        $handler = app(EventSubHandler::class);

        $handler->handleNotification($this->notification('m1', 'stream.online', [
            'id' => 's9', 'type' => 'live', 'started_at' => '2026-11-22T19:59:00Z',
        ]));
        $this->assertSame('s9', StreamSession::current()->twitch_stream_id);

        $handler->handleNotification($this->notification('m2', 'stream.offline', []));
        $this->assertNull(StreamSession::current());
    }

    public function test_track_command_records_who_is_in_chat(): void
    {
        $this->account();
        Http::fake([
            TwitchClient::HELIX_URL.'/streams*' => Http::response(['data' => [[
                'id' => 's1', 'started_at' => '2026-11-22T19:00:00Z', 'title' => 'Live', 'game_name' => 'Just Chatting',
            ]]]),
            TwitchClient::HELIX_URL.'/chat/chatters*' => Http::response([
                'data' => [
                    ['user_id' => '1', 'user_login' => 'alice', 'user_name' => 'Alice'],
                    ['user_id' => '2', 'user_login' => 'bob', 'user_name' => 'Bob'],
                ],
                'pagination' => [],
            ]),
        ]);

        $this->artisan('twitch:track-chatters')->assertSuccessful();

        $this->assertSame('Just Chatting', StreamSession::current()->game_name);
        $this->assertSame(2, ChatterStreamStat::count());
        Http::assertSent(fn ($r) => str_contains($r->url(), 'moderator_id=123'));
    }

    public function test_track_command_fetches_missing_avatars(): void
    {
        $this->account();
        Http::fake([
            TwitchClient::HELIX_URL.'/streams*' => Http::response(['data' => [[
                'id' => 's1', 'started_at' => '2026-11-22T19:00:00Z', 'title' => 'Live', 'game_name' => 'Just Chatting',
            ]]]),
            TwitchClient::HELIX_URL.'/chat/chatters*' => Http::response([
                'data' => [['user_id' => '1', 'user_login' => 'alice', 'user_name' => 'Alice']],
                'pagination' => [],
            ]),
            TwitchClient::HELIX_URL.'/users*' => Http::response(['data' => [[
                'id' => '1', 'login' => 'alice', 'display_name' => 'Alice', 'profile_image_url' => 'https://img/alice.png',
            ]]]),
        ]);

        $this->artisan('twitch:track-chatters')->assertSuccessful();

        $this->assertSame('https://img/alice.png', Chatter::where('login', 'alice')->value('profile_image_url'));
        Http::assertSent(fn ($r) => str_contains($r->url(), '/users?id=1'));
    }

    public function test_track_command_closes_session_when_offline(): void
    {
        $this->account();
        $this->tracker->openSession('s1', now()->subHour());
        Http::fake([TwitchClient::HELIX_URL.'/streams*' => Http::response(['data' => []])]);

        $this->artisan('twitch:track-chatters')->assertSuccessful();

        $this->assertNull(StreamSession::current());
    }

    public function test_overview_lists_online_chatters_and_redemptions(): void
    {
        $account = $this->account();
        $session = $this->tracker->openSession('s1', now()->subHour(), 'Live', 'Just Chatting');
        $this->tracker->recordPresence($session, [['user_id' => '1', 'user_login' => 'alice', 'user_name' => 'Alice']], now()->subMinutes(10));
        $this->tracker->recordPresence($session, [['user_id' => '2', 'user_login' => 'bob', 'user_name' => 'Bob']], now()->subSeconds(15));
        $this->tracker->recordPresence($session, [['user_id' => '2', 'user_login' => 'bob', 'user_name' => 'Bob']], now());
        $this->tracker->recordMessage('2', 'bob', 'Bob');

        $this->actingAs($account->user)
            ->getJson('/api/chat/overview')
            ->assertOk()
            ->assertJsonPath('live', true)
            ->assertJsonPath('totals.online', 1)
            ->assertJsonPath('totals.chatters', 2)
            ->assertJsonPath('totals.messages', 1)
            ->assertJsonPath('chatters.0.login', 'bob')
            ->assertJsonPath('chatters.0.online', true)
            ->assertJsonPath('chatters.0.watch_seconds', 15)
            ->assertJsonPath('chatters.0.periods', 1)
            ->assertJsonPath('chatters.1.online', false);
    }

    public function test_overview_requires_login(): void
    {
        $this->getJson('/api/chat/overview')->assertUnauthorized();
    }

    public function test_me_reports_missing_scopes(): void
    {
        $account = $this->account(['scopes' => ['user:read:email']]);

        $this->actingAs($account->user)
            ->getJson('/api/me')
            ->assertJsonPath('channel.missing_scopes', ['user:read:chat', 'moderator:read:chatters', 'channel:read:redemptions']);
    }

    /**
     * @param  array<string, mixed>  $event
     * @return array<string, mixed>
     */
    private function notification(string $id, string $type, array $event): array
    {
        return [
            'metadata' => ['message_id' => $id, 'message_type' => 'notification', 'message_timestamp' => now()->toIso8601String()],
            'payload' => ['subscription' => ['type' => $type], 'event' => $event],
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function account(array $overrides = []): TwitchAccount
    {
        return TwitchAccount::create(array_merge([
            'user_id' => User::factory()->create()->id,
            'twitch_id' => '123',
            'login' => 'streamer',
            'display_name' => 'Streamer',
            'access_token' => 'access-1',
            'refresh_token' => 'refresh-1',
            'scopes' => ['user:read:email', 'user:read:chat', 'moderator:read:chatters', 'channel:read:redemptions'],
            'expires_at' => now()->addHours(3),
            'last_validated_at' => now(),
        ], $overrides));
    }
}
