<?php

namespace Tests\Feature;

use App\Models\Chatter;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Economy\EconomyService;
use App\Services\Economy\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EconomyAdminTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['name' => 'Streamer']);
    }

    public function test_requires_login(): void
    {
        $this->getJson('/api/economy/summary')->assertUnauthorized();
        $this->postJson('/api/economy/reset', [])->assertUnauthorized();
    }

    public function test_summary_counts_balances(): void
    {
        $this->rich('alice', 300, 1000);
        $this->rich('bob', 0, 50);

        $this->actingAs($this->admin)
            ->getJson('/api/economy/summary')
            ->assertOk()
            ->assertJson(['xp_total' => 1050, 'coins_total' => 300, 'with_xp' => 2, 'with_coins' => 1]);
    }

    public function test_update_settings_validates_and_saves(): void
    {
        $this->actingAs($this->admin)
            ->putJson('/api/economy/settings', ['values' => ['message_xp' => 'abc', 'level_titles' => '[1,2]']])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['values.message_xp', 'values.level_titles']);

        $this->actingAs($this->admin)
            ->putJson('/api/economy/settings', ['values' => ['message_xp' => '8', 'sub_mult_t1' => '1.3']])
            ->assertOk()
            ->assertJsonFragment(['key' => 'message_xp', 'value' => '8']);

        $this->assertSame(8, app(SettingsService::class)->int('message_xp'));
    }

    public function test_manual_adjust_goes_through_the_ledger(): void
    {
        $alice = $this->rich('alice', 100, 0);

        $this->actingAs($this->admin)
            ->postJson('/api/economy/adjust', ['user_id' => $alice->twitch_user_id, 'coins' => 50, 'xp' => 200, 'reason' => 'Ganhou o sorteio'])
            ->assertOk()
            ->assertJson(['coins' => 150, 'xp' => 200]);

        $t = Transaction::where('type', Transaction::MANUAL)->sole();
        $this->assertSame('Ganhou o sorteio', $t->meta['reason']);
        $this->assertSame('Streamer', $t->meta['by']);
    }

    public function test_manual_adjust_cannot_leave_negative_coins(): void
    {
        $alice = $this->rich('alice', 10, 0);

        $this->actingAs($this->admin)
            ->postJson('/api/economy/adjust', ['user_id' => $alice->twitch_user_id, 'coins' => -50])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('coins');

        $this->assertSame(10, $alice->fresh()->coins);
    }

    public function test_reset_needs_confirmation(): void
    {
        $this->rich('alice', 100, 100);

        $this->actingAs($this->admin)
            ->postJson('/api/economy/reset', ['scope' => 'all', 'targets' => ['xp'], 'confirm' => 'sim'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('confirm');

        $this->assertSame(100, Chatter::where('login', 'alice')->value('xp'));
    }

    public function test_reset_one_person_only_xp(): void
    {
        $alice = $this->rich('alice', 300, 2000);
        $bob = $this->rich('bob', 300, 2000);

        $this->actingAs($this->admin)
            ->postJson('/api/economy/reset', ['scope' => 'user', 'user_id' => $alice->twitch_user_id, 'targets' => ['xp'], 'confirm' => 'ZERAR'])
            ->assertOk()
            ->assertJson(['affected' => 1]);

        $alice->refresh();
        $this->assertSame(0, $alice->xp);
        $this->assertSame(1, $alice->level);
        $this->assertSame(300, $alice->coins);
        $this->assertSame(2000, $bob->fresh()->xp);
        // Histórico continua e o saldo bate com o livro-caixa.
        $this->assertSame(0, (int) $alice->transactions()->sum('xp_delta'));
        $this->assertSame(1, $alice->transactions()->where('type', Transaction::RESET)->count());
    }

    public function test_reset_everyone_everything(): void
    {
        $alice = $this->rich('alice', 300, 2000);
        $alice->forceFill(['current_streak' => 3, 'best_streak' => 5])->save();
        $this->rich('bob', 40, 10);

        $this->actingAs($this->admin)
            ->postJson('/api/economy/reset', ['scope' => 'all', 'targets' => ['xp', 'coins', 'streak'], 'confirm' => 'ZERAR'])
            ->assertOk()
            ->assertJson(['affected' => 2]);

        $this->assertSame(0, (int) Chatter::sum('xp'));
        $this->assertSame(0, (int) Chatter::sum('coins'));
        $this->assertSame(0, (int) Chatter::sum('best_streak'));
        $this->assertSame(0, (int) Transaction::sum('coins_delta'));
    }

    private function rich(string $login, int $coins, int $xp): Chatter
    {
        $chatter = Chatter::remember((string) crc32($login), $login, ucfirst($login));

        if ($coins || $xp) {
            app(EconomyService::class)->grant($chatter->id, Transaction::SUB, $coins, $xp);
        }

        return $chatter->fresh();
    }
}
