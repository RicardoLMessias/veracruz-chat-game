<?php

namespace Tests\Feature;

use App\Models\AppSetting;
use App\Models\User;
use App\Services\Twitch\TwitchClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SetupTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.twitch' => [
            'client_id' => '',
            'client_secret' => '',
            'redirect_uri' => 'http://localhost:5173/auth/twitch/callback',
            'scopes' => 'user:read:email',
        ]]);
        $this->app->forgetInstance(TwitchClient::class);
        Http::preventStrayRequests();
    }

    public function test_status_reports_missing_credentials(): void
    {
        $this->getJson('/api/setup')
            ->assertOk()
            ->assertJson([
                'twitch_configured' => false,
                'redirect_uri' => 'http://localhost:5173/auth/twitch/callback',
            ]);
    }

    public function test_saves_valid_credentials_encrypted(): void
    {
        Http::fake([TwitchClient::TOKEN_URL => Http::response(['access_token' => 'app-token', 'expires_in' => 3600])]);

        $this->postJson('/api/setup/twitch', ['client_id' => 'abc123', 'client_secret' => 'secret456'])
            ->assertOk()
            ->assertJson(['twitch_configured' => true]);

        $this->assertSame('secret456', AppSetting::getValue(TwitchClient::SETTING_CLIENT_SECRET));
        $this->assertNotSame('secret456', DB::table('app_settings')->where('key', TwitchClient::SETTING_CLIENT_SECRET)->value('value'));

        $this->getJson('/api/setup')->assertJson(['twitch_configured' => true])
            ->assertJsonMissingPath('client_id_hint');

        Http::assertSent(fn ($request) => $request['grant_type'] === 'client_credentials');
    }

    public function test_rejects_credentials_twitch_does_not_know(): void
    {
        Http::fake([TwitchClient::TOKEN_URL => Http::response(['message' => 'invalid client secret'], 403)]);

        $this->postJson('/api/setup/twitch', ['client_id' => 'abc123', 'client_secret' => 'wrong'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('client_secret');

        $this->assertNull(AppSetting::getValue(TwitchClient::SETTING_CLIENT_ID));
    }

    public function test_validates_input(): void
    {
        $this->postJson('/api/setup/twitch', ['client_id' => 'tem espaço', 'client_secret' => ''])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['client_id', 'client_secret']);
    }

    public function test_guest_cannot_overwrite_after_configured(): void
    {
        AppSetting::setValue(TwitchClient::SETTING_CLIENT_ID, 'abc123');
        AppSetting::setValue(TwitchClient::SETTING_CLIENT_SECRET, 'secret456');
        $this->app->forgetInstance(TwitchClient::class);

        $this->postJson('/api/setup/twitch', ['client_id' => 'evil', 'client_secret' => 'evil'])
            ->assertForbidden();

        $this->assertSame('abc123', AppSetting::getValue(TwitchClient::SETTING_CLIENT_ID));
    }

    public function test_logged_in_user_can_replace_credentials(): void
    {
        AppSetting::setValue(TwitchClient::SETTING_CLIENT_ID, 'abc123');
        AppSetting::setValue(TwitchClient::SETTING_CLIENT_SECRET, 'secret456');
        $this->app->forgetInstance(TwitchClient::class);
        Http::fake([TwitchClient::TOKEN_URL => Http::response(['access_token' => 'app-token'])]);

        $this->actingAs(User::factory()->create())
            ->postJson('/api/setup/twitch', ['client_id' => 'new123', 'client_secret' => 'new456'])
            ->assertOk();

        $this->assertSame('new123', AppSetting::getValue(TwitchClient::SETTING_CLIENT_ID));
    }

    public function test_login_uses_saved_credentials(): void
    {
        AppSetting::setValue(TwitchClient::SETTING_CLIENT_ID, 'fromdb');
        AppSetting::setValue(TwitchClient::SETTING_CLIENT_SECRET, 'secret');
        $this->app->forgetInstance(TwitchClient::class);

        $location = $this->get('/auth/twitch/redirect')->headers->get('Location');

        $this->assertStringContainsString('client_id=fromdb', $location);
    }
}
