<?php

namespace Tests\Feature;

use App\Models\ChannelEvent;
use App\Models\Chatter;
use App\Models\TwitchAccount;
use App\Models\User;
use App\Services\Chat\ChatTracker;
use App\Services\Twitch\EventSubHandler;
use App\Services\Twitch\TwitchClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class InteractionsTest extends TestCase
{
    use RefreshDatabase;

    private EventSubHandler $handler;

    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.twitch' => [
            'client_id' => 'test-client',
            'client_secret' => 'test-secret',
            'redirect_uri' => 'http://localhost:5173/auth/twitch/callback',
            'scopes' => 'user:read:email user:read:chat moderator:read:chatters channel:read:redemptions bits:read',
        ]]);
        $this->app->forgetInstance(TwitchClient::class);
        Http::preventStrayRequests();
        Carbon::setTestNow('2026-11-22 20:00:00');

        app(ChatTracker::class)->openSession('s1', now()->subHour());
        $this->handler = app(EventSubHandler::class);
    }

    public function test_prime_sub_is_recorded_for_the_user(): void
    {
        $this->notice('sub', $this->user('1', 'alice'), ['sub' => ['sub_tier' => '1000', 'is_prime' => true, 'duration_months' => 1]]);

        $event = ChannelEvent::sole();
        $this->assertSame(ChannelEvent::TYPE_SUB, $event->type);
        $this->assertTrue($event->is_prime);
        $this->assertSame('1000', $event->tier);
        $this->assertSame('alice', $event->chatter->login);
    }

    public function test_resub_keeps_months_streak_and_message(): void
    {
        $this->notice('resub', $this->user('1', 'alice'), [
            'message' => ['text' => '8 meses!'],
            'resub' => [
                'cumulative_months' => 8, 'streak_months' => 3, 'sub_tier' => '2000',
                'is_prime' => false, 'is_gift' => false, 'duration_months' => 1,
            ],
        ]);

        $event = ChannelEvent::sole();
        $this->assertSame(ChannelEvent::TYPE_RESUB, $event->type);
        $this->assertSame(8, $event->months);
        $this->assertSame(3, $event->streak_months);
        $this->assertSame('8 meses!', $event->user_input);
    }

    public function test_single_gift_counts_for_giver_and_receiver(): void
    {
        $this->notice('sub_gift', $this->user('1', 'alice'), ['sub_gift' => [
            'sub_tier' => '1000', 'duration_months' => 1, 'community_gift_id' => null,
            'recipient_user_id' => '2', 'recipient_user_login' => 'bob', 'recipient_user_name' => 'Bob',
        ]]);

        $gift = ChannelEvent::where('type', ChannelEvent::TYPE_GIFT)->sole();
        $this->assertSame('alice', $gift->chatter->login);
        $this->assertSame(1, $gift->amount);
        $this->assertSame('Bob', $gift->related_user_name);

        $received = ChannelEvent::where('type', ChannelEvent::TYPE_SUB)->sole();
        $this->assertSame('bob', $received->chatter->login);
        $this->assertTrue($received->is_gift);
        $this->assertSame('Alice', $received->related_user_name);
    }

    public function test_community_gift_counts_giver_once(): void
    {
        $alice = $this->user('1', 'alice');
        $this->notice('community_sub_gift', $alice, ['community_sub_gift' => ['id' => 'c1', 'total' => 3, 'sub_tier' => '1000']]);

        foreach (['2' => 'bob', '3' => 'carol', '4' => 'dave'] as $id => $login) {
            $this->notice('sub_gift', $alice, ['sub_gift' => [
                'sub_tier' => '1000', 'duration_months' => 1, 'community_gift_id' => 'c1',
                'recipient_user_id' => $id, 'recipient_user_login' => $login, 'recipient_user_name' => ucfirst($login),
            ]]);
        }

        $this->assertSame(3, (int) ChannelEvent::where('type', ChannelEvent::TYPE_GIFT)->sum('amount'));
        $this->assertSame(1, ChannelEvent::where('type', ChannelEvent::TYPE_GIFT)->count());
        $this->assertSame(3, ChannelEvent::where('type', ChannelEvent::TYPE_SUB)->where('is_gift', true)->count());
    }

    public function test_anonymous_gift_has_no_user(): void
    {
        $this->notice('community_sub_gift', ['chatter_is_anonymous' => true], ['community_sub_gift' => ['id' => 'c2', 'total' => 5, 'sub_tier' => '1000']]);

        $gift = ChannelEvent::sole();
        $this->assertNull($gift->chatter_id);
        $this->assertTrue($gift->is_anonymous);
        $this->assertSame('Anônimo', $gift->user_name);
    }

    public function test_bits_are_recorded_per_user_and_anonymous(): void
    {
        $this->event('channel.cheer', [
            'is_anonymous' => false, 'user_id' => '1', 'user_login' => 'alice', 'user_name' => 'Alice',
            'bits' => 100, 'message' => 'Cheer100 boa live',
        ]);
        $this->event('channel.cheer', ['is_anonymous' => true, 'user_id' => null, 'bits' => 50, 'message' => '']);

        $alice = Chatter::where('login', 'alice')->sole();
        $this->assertSame(100, (int) $alice->events()->sum('amount'));
        $this->assertSame(1, ChannelEvent::whereNull('chatter_id')->where('type', ChannelEvent::TYPE_BITS)->count());
    }

    public function test_prime_upgrade(): void
    {
        $this->notice('prime_paid_upgrade', $this->user('1', 'alice'), ['prime_paid_upgrade' => ['sub_tier' => '1000']]);

        $this->assertSame(ChannelEvent::TYPE_UPGRADE, ChannelEvent::sole()->type);
    }

    public function test_overview_totals_and_per_user_interactions(): void
    {
        $account = $this->account();
        app(ChatTracker::class)->recordMessage('1', 'alice', 'Alice');
        $this->notice('sub', $this->user('1', 'alice'), ['sub' => ['sub_tier' => '1000', 'is_prime' => true, 'duration_months' => 1]]);
        $this->event('channel.cheer', [
            'is_anonymous' => false, 'user_id' => '1', 'user_login' => 'alice', 'user_name' => 'Alice', 'bits' => 200, 'message' => '',
        ]);

        $this->actingAs($account->user)
            ->getJson('/api/chat/overview')
            ->assertOk()
            ->assertJsonPath('totals.subs', 1)
            ->assertJsonPath('totals.bits', 200)
            ->assertJsonPath('chatters.0.login', 'alice')
            ->assertJsonPath('chatters.0.bits', 200)
            ->assertJsonPath('chatters.0.sub.is_prime', true)
            ->assertJsonCount(2, 'events');
    }

    public function test_community_ranking_and_user_profile(): void
    {
        $account = $this->account();
        app(ChatTracker::class)->recordMessage('1', 'alice', 'Alice');
        app(ChatTracker::class)->recordMessage('2', 'bob', 'Bob');
        $this->event('channel.cheer', [
            'is_anonymous' => false, 'user_id' => '2', 'user_login' => 'bob', 'user_name' => 'Bob', 'bits' => 500, 'message' => '',
        ]);
        $this->event('channel.channel_points_custom_reward_redemption.add', [
            'id' => 'r1', 'user_id' => '1', 'user_login' => 'alice', 'user_name' => 'Alice', 'user_input' => '',
            'status' => 'fulfilled', 'reward' => ['title' => 'Hidratar', 'cost' => 100], 'redeemed_at' => '2026-11-22T20:00:00Z',
        ]);

        $this->actingAs($account->user)
            ->getJson('/api/community?sort=bits')
            ->assertOk()
            ->assertJsonPath('data.0.login', 'bob')
            ->assertJsonPath('data.0.bits_total', 500)
            ->assertJsonPath('meta.total', 2);

        $this->actingAs($account->user)
            ->getJson('/api/community/1')
            ->assertOk()
            ->assertJsonPath('login', 'alice')
            ->assertJsonPath('totals.message_count', 1)
            ->assertJsonPath('totals.redemptions_count', 1)
            ->assertJsonPath('totals.points_spent', 100)
            ->assertJsonPath('lives.0.redemptions', 1)
            ->assertJsonPath('timeline.0.title', 'Hidratar');

        $this->actingAs($account->user)->getJson('/api/community/999')->assertNotFound();
    }

    /**
     * @return array<string, mixed>
     */
    private function user(string $id, string $login): array
    {
        return [
            'chatter_is_anonymous' => false,
            'chatter_user_id' => $id,
            'chatter_user_login' => $login,
            'chatter_user_name' => ucfirst($login),
        ];
    }

    /**
     * @param  array<string, mixed>  $user
     * @param  array<string, mixed>  $extra
     */
    private function notice(string $noticeType, array $user, array $extra): void
    {
        $this->event('channel.chat.notification', array_merge($user, [
            'message_id' => 'n'.(++$this->seq),
            'notice_type' => $noticeType,
            'message' => ['text' => ''],
        ], $extra));
    }

    /**
     * @param  array<string, mixed>  $event
     */
    private function event(string $type, array $event): void
    {
        $this->handler->handleNotification([
            'metadata' => [
                'message_id' => 'm'.(++$this->seq),
                'message_type' => 'notification',
                'message_timestamp' => now()->toIso8601String(),
            ],
            'payload' => ['subscription' => ['type' => $type], 'event' => $event],
        ]);
    }

    private function account(): TwitchAccount
    {
        return TwitchAccount::create([
            'user_id' => User::factory()->create()->id,
            'twitch_id' => '999000',
            'login' => 'streamer',
            'display_name' => 'Streamer',
            'access_token' => 'access-1',
            'refresh_token' => 'refresh-1',
            'scopes' => ['user:read:email', 'user:read:chat', 'moderator:read:chatters', 'channel:read:redemptions', 'bits:read'],
            'expires_at' => now()->addHours(3),
            'last_validated_at' => now(),
        ]);
    }
}
