<?php

namespace Tests\Feature;

use App\Models\ChannelEvent;
use App\Models\Chatter;
use App\Models\ChatterStreamStat;
use App\Models\StreamSession;
use App\Models\Transaction;
use App\Models\TwitchAccount;
use App\Models\User;
use App\Services\Chat\ChatTracker;
use App\Services\Economy\EconomyService;
use App\Services\Economy\InsufficientFundsException;
use App\Services\Economy\LevelService;
use App\Services\Economy\RewardService;
use App\Services\Twitch\EventSubHandler;
use App\Services\Twitch\TwitchClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class EconomyTest extends TestCase
{
    use RefreshDatabase;

    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.twitch' => [
            'client_id' => 'test-client',
            'client_secret' => 'test-secret',
            'redirect_uri' => 'http://localhost:5173/auth/twitch/callback',
            'scopes' => 'user:read:chat moderator:read:chatters channel:read:redemptions bits:read channel:read:subscriptions',
            'presence_interval' => 15,
        ]]);
        $this->app->forgetInstance(TwitchClient::class);
        Http::preventStrayRequests();
        Carbon::setTestNow('2026-11-22 20:00:00');
    }

    public function test_levels_match_the_spec_table(): void
    {
        $levels = app(LevelService::class);

        $this->assertSame(1, $levels->levelFromXp(0));
        $this->assertSame(4, $levels->levelFromXp(1702));
        $this->assertSame(5, $levels->levelFromXp(1703));
        $this->assertSame(10, $levels->levelFromXp(11106));
        $this->assertSame('Sobrevivente', $levels->titleFor(5));
        $this->assertSame('Lenda de Vera Cruz', $levels->titleFor(31));
    }

    public function test_grant_keeps_balance_equal_to_ledger(): void
    {
        $economy = app(EconomyService::class);
        $alice = $this->chatter('alice');

        $economy->grant($alice->id, Transaction::SUB, 300, 500);
        $economy->grant($alice->id, Transaction::MANUAL, -100, 0);
        $result = $economy->grant($alice->id, Transaction::MESSAGE, 0, 1300);

        $alice->refresh();
        $this->assertSame(200, $alice->coins);
        $this->assertSame(1800, $alice->xp);
        $this->assertSame(5, $alice->level);
        $this->assertTrue($result['leveled_up']);
        $this->assertSame($alice->coins, (int) $alice->transactions()->sum('coins_delta'));
        $this->assertSame($alice->xp, (int) $alice->transactions()->sum('xp_delta'));
    }

    public function test_spending_without_balance_fails_and_writes_nothing(): void
    {
        $alice = $this->chatter('alice');

        $this->expectException(InsufficientFundsException::class);

        try {
            app(EconomyService::class)->grant($alice->id, Transaction::MANUAL, -50, 0);
        } finally {
            $this->assertSame(0, Transaction::count());
            $this->assertSame(0, $alice->fresh()->coins);
        }
    }

    public function test_xp_never_goes_negative(): void
    {
        $economy = app(EconomyService::class);
        $alice = $this->chatter('alice');
        $economy->grant($alice->id, Transaction::MESSAGE, 0, 30);

        $economy->grant($alice->id, Transaction::MANUAL, 0, -100);

        $this->assertSame(0, $alice->fresh()->xp);
        $this->assertSame(-30, Transaction::latest('id')->first()->xp_delta);
    }

    public function test_stochastic_round_keeps_the_average(): void
    {
        mt_srand(42);
        $total = 0;

        for ($i = 0; $i < 10000; $i++) {
            $total += EconomyService::stochasticRound(2.3);
        }

        $this->assertEqualsWithDelta(2.3, $total / 10000, 0.03);
    }

    public function test_presence_pays_xp_once_per_second_of_chat(): void
    {
        $session = app(ChatTracker::class)->openSession('s1', now());
        $this->presence($session, 'alice', 0, 120);

        app(RewardService::class)->awardPresence();
        app(RewardService::class)->awardPresence(); // nada novo para pagar

        // 2 XP/min × 2 min, sem multiplicadores
        $this->assertSame(4, Chatter::where('login', 'alice')->value('xp'));
        $this->assertSame(1, Transaction::where('type', Transaction::PRESENCE)->count());
        $this->assertSame(0, Chatter::where('login', 'alice')->value('coins'));
    }

    public function test_active_chatter_gets_presence_bonus(): void
    {
        $session = app(ChatTracker::class)->openSession('s1', now());
        $this->presence($session, 'alice', 0, 120);
        ChatterStreamStat::query()->update(['last_message_at' => now()]);

        app(RewardService::class)->awardPresence();

        $this->assertSame(1.5, Transaction::where('type', Transaction::PRESENCE)->sole()->meta['mult']);
    }

    public function test_messages_give_xp_up_to_three_per_minute(): void
    {
        $this->account();
        app(ChatTracker::class)->openSession('s1', now());

        for ($i = 0; $i < 5; $i++) {
            app(ChatTracker::class)->recordMessage('1', 'alice', 'Alice', now()->addSeconds($i));
        }

        $this->assertSame(3, Transaction::where('type', Transaction::MESSAGE)->count());
        $this->assertSame(15, Chatter::where('login', 'alice')->value('xp'));
        $this->assertSame(5, ChatterStreamStat::sole()->message_count);

        Carbon::setTestNow(now()->addMinute());
        app(ChatTracker::class)->recordMessage('1', 'alice', 'Alice');
        $this->assertSame(4, Transaction::where('type', Transaction::MESSAGE)->count());
    }

    public function test_redemptions_give_xp_up_to_three_per_minute(): void
    {
        $this->account();
        app(ChatTracker::class)->openSession('s1', now());

        for ($i = 1; $i <= 4; $i++) {
            $this->event('channel.channel_points_custom_reward_redemption.add', [
                'id' => "r{$i}", 'user_id' => '1', 'user_login' => 'alice', 'user_name' => 'Alice', 'user_input' => '',
                'status' => 'fulfilled', 'reward' => ['title' => 'Hidratar', 'cost' => 100], 'redeemed_at' => now()->toIso8601String(),
            ]);
        }

        $this->assertSame(4, ChannelEvent::where('type', ChannelEvent::TYPE_REDEMPTION)->count());
        $this->assertSame(3, Transaction::where('type', Transaction::REDEMPTION)->count());
        $this->assertSame(30, Chatter::where('login', 'alice')->value('xp'));
    }

    public function test_prime_sub_gives_bonus_with_tier_one_multiplier(): void
    {
        $this->account();
        $this->notice('sub', 'alice', ['sub' => ['sub_tier' => '1000', 'is_prime' => true, 'duration_months' => 1]]);

        $alice = Chatter::where('login', 'alice')->sole();
        $this->assertSame(1, $alice->sub_tier);
        $this->assertTrue($alice->sub_is_prime);
        $this->assertSame(375, $alice->coins); // 300 × 1,25
        $this->assertSame(625, $alice->xp); // 500 × 1,25
    }

    public function test_tier_three_resub_doubles_and_multiplies_messages_after(): void
    {
        $this->account();
        app(ChatTracker::class)->openSession('s1', now());
        $this->notice('resub', 'alice', ['resub' => [
            'cumulative_months' => 4, 'streak_months' => 4, 'sub_tier' => '3000', 'is_prime' => false, 'is_gift' => false,
        ]]);

        app(ChatTracker::class)->recordMessage('1', 'alice', 'Alice');

        $this->assertSame(600, Chatter::where('login', 'alice')->value('coins'));
        $this->assertSame(10, Transaction::where('type', Transaction::MESSAGE)->sole()->xp_delta); // 5 × 2
    }

    public function test_gifts_reward_giver_and_receiver(): void
    {
        $this->account();
        $this->notice('community_sub_gift', 'alice', ['community_sub_gift' => ['id' => 'c1', 'total' => 5, 'sub_tier' => '1000']]);
        $this->notice('sub_gift', 'alice', ['sub_gift' => [
            'sub_tier' => '1000', 'duration_months' => 1, 'community_gift_id' => 'c1',
            'recipient_user_id' => '2', 'recipient_user_login' => 'bob', 'recipient_user_name' => 'Bob',
        ]]);

        $alice = Chatter::where('login', 'alice')->sole();
        $this->assertSame(1000, $alice->coins); // 5 × 200
        $this->assertSame(1500, $alice->xp); // 5 × 300

        $bob = Chatter::where('login', 'bob')->sole();
        $this->assertSame(1, $bob->sub_tier);
        $this->assertSame(Transaction::GIFT_RECEIVED, $bob->transactions()->sole()->type);
    }

    public function test_bits_are_proportional_and_anonymous_gets_nothing(): void
    {
        $this->account();
        $this->event('channel.cheer', [
            'is_anonymous' => false, 'user_id' => '1', 'user_login' => 'alice', 'user_name' => 'Alice', 'bits' => 200, 'message' => '',
        ]);
        $this->event('channel.cheer', ['is_anonymous' => true, 'bits' => 1000, 'message' => '']);

        $alice = Chatter::where('login', 'alice')->sole();
        $this->assertSame(30, $alice->coins); // 2 × 15
        $this->assertSame(60, $alice->xp); // 2 × 30
        $this->assertSame(1, Transaction::count());
    }

    public function test_broadcaster_and_bots_earn_nothing(): void
    {
        $this->account(); // canal = "streamer"
        app(ChatTracker::class)->openSession('s1', now());
        $this->event('channel.cheer', [
            'is_anonymous' => false, 'user_id' => '123', 'user_login' => 'streamer', 'user_name' => 'Streamer', 'bits' => 500, 'message' => '',
        ]);
        app(ChatTracker::class)->recordMessage('50', 'nightbot', 'Nightbot');

        $this->assertSame(0, Transaction::count());
    }

    public function test_streak_grows_on_consecutive_lives_and_boosts_presence(): void
    {
        $tracker = app(ChatTracker::class);
        $rewards = app(RewardService::class);

        foreach (['s1', 's2', 's3'] as $i => $streamId) {
            Carbon::setTestNow(Carbon::parse('2026-11-20 20:00:00')->addDays($i));
            $session = $tracker->openSession($streamId, now());
            $this->presence($session, 'alice', 0, 16 * 60);
            $rewards->awardPresence();
            $tracker->closeOpenSessions();
        }

        $alice = Chatter::where('login', 'alice')->sole();
        $this->assertSame(3, $alice->current_streak);
        $this->assertSame(3, $alice->best_streak);
        $this->assertSame(1.2, Transaction::where('type', Transaction::PRESENCE)->latest('id')->first()->meta['mult']);
    }

    public function test_missing_a_live_resets_the_streak(): void
    {
        $tracker = app(ChatTracker::class);
        $rewards = app(RewardService::class);

        $days = ['s1' => 0, 's2' => 1, 's3' => 2];
        foreach ($days as $streamId => $day) {
            Carbon::setTestNow(Carbon::parse('2026-11-20 20:00:00')->addDays($day));
            $session = $tracker->openSession($streamId, now());

            if ($streamId !== 's2') { // faltou na s2
                $this->presence($session, 'alice', 0, 16 * 60);
                $rewards->awardPresence();
            }

            $tracker->closeOpenSessions();
        }

        $this->assertSame(1, Chatter::where('login', 'alice')->value('current_streak'));
    }

    public function test_sync_subs_sets_and_clears_tiers(): void
    {
        $this->account();
        $old = $this->chatter('oldsub');
        $old->forceFill(['sub_tier' => 2])->save();
        Http::fake([TwitchClient::HELIX_URL.'/subscriptions*' => Http::response([
            'data' => [['user_id' => '7', 'user_login' => 'carol', 'user_name' => 'Carol', 'tier' => '2000', 'is_gift' => false]],
            'pagination' => [],
        ])]);

        $this->artisan('twitch:sync-subs')->assertSuccessful();

        $this->assertSame(2, Chatter::where('login', 'carol')->value('sub_tier'));
        $this->assertSame(0, $old->fresh()->sub_tier);
    }

    private function chatter(string $login): Chatter
    {
        return Chatter::remember((string) crc32($login), $login, ucfirst($login));
    }

    /**
     * Presença contínua de $from a $to segundos (checagens a cada 15s).
     */
    private function presence(StreamSession $session, string $login, int $from, int $to): void
    {
        for ($s = $from; $s <= $to; $s += 15) {
            app(ChatTracker::class)->recordPresence($session, [[
                'user_id' => (string) crc32($login), 'user_login' => $login, 'user_name' => ucfirst($login),
            ]], now()->addSeconds($s));
        }
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function notice(string $noticeType, string $login, array $extra): void
    {
        $this->event('channel.chat.notification', array_merge([
            'chatter_is_anonymous' => false,
            'chatter_user_id' => '1',
            'chatter_user_login' => $login,
            'chatter_user_name' => ucfirst($login),
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
        app(EventSubHandler::class)->handleNotification([
            'metadata' => ['message_id' => 'm'.(++$this->seq), 'message_type' => 'notification', 'message_timestamp' => now()->toIso8601String()],
            'payload' => ['subscription' => ['type' => $type], 'event' => $event],
        ]);
    }

    private function account(): TwitchAccount
    {
        return TwitchAccount::create([
            'user_id' => User::factory()->create()->id,
            'twitch_id' => '123',
            'login' => 'streamer',
            'display_name' => 'Streamer',
            'access_token' => 'access-1',
            'refresh_token' => 'refresh-1',
            'scopes' => explode(' ', config('services.twitch.scopes')),
            'expires_at' => now()->addHours(3),
            'last_validated_at' => now(),
        ]);
    }
}
