<?php

namespace Tests\Feature\Iptv;

use App\Models\Iptv\ChannelFavorite;
use App\Models\Iptv\IptvPlaybackResource;
use App\Models\Iptv\IptvPlaybackSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\InteractsWithIptv;
use Tests\TestCase;

class IptvBrowsingAndCleanupTest extends TestCase
{
    use InteractsWithIptv;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->loadIptvRoutes();
        $this->allowPublicIptvDns();
    }

    public function test_favorites_are_kept_separate_for_each_user(): void
    {
        $first = User::factory()->create(['is_active' => true]);
        $second = User::factory()->create(['is_active' => true]);
        $channel = $this->makeChannel($this->makeProvider());

        $this->actingAs($first)
            ->post(route('iptv.favorites.store', $channel))
            ->assertOk()
            ->assertSee('aria-pressed="true"', escape: false);

        $this->assertDatabaseHas('channel_favorites', [
            'user_id' => $first->id,
            'channel_id' => $channel->id,
        ]);

        $this->actingAs($first)
            ->get(route('iptv.channels.index', ['favorites' => 1]))
            ->assertOk()
            ->assertSee($channel->name);

        $this->actingAs($second)
            ->get(route('iptv.channels.index', ['favorites' => 1]))
            ->assertOk()
            ->assertDontSee($channel->name);
    }

    public function test_prune_and_e2e_cleanup_remove_only_explicitly_selected_state(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $e2eUser = User::factory()->create([
            'name' => 'IPTV Playback User [E2E]',
            'email' => 'iptv-e2e@example.test',
            'is_active' => true,
            'preferences' => ['e2e' => true],
            'remember_token' => 'remember-me',
        ]);
        $regularUser = User::factory()->create([
            'email' => 'regular@example.test',
            'is_active' => true,
        ]);
        $e2eProvider = $this->makeProvider([
            'name' => 'E2E IPTV',
            'config' => ['api' => 'xtream', 'e2e' => true],
        ]);
        $e2eChannel = $this->makeChannel($e2eProvider);
        $regularProvider = $this->makeProvider(['name' => 'Personal IPTV']);
        $this->makeChannel($regularProvider, ['external_id' => '202', 'name' => 'Personal News']);

        $expired = IptvPlaybackSession::query()->create([
            'user_id' => $user->id,
            'channel_id' => $e2eChannel->id,
            'status' => 'created',
            'expires_at' => now()->subMinute(),
        ]);
        IptvPlaybackResource::query()->create([
            'iptv_playback_session_id' => $expired->id,
            'upstream_fingerprint' => hash('sha256', 'expired'),
            'upstream_url' => 'https://media.example.test/expired.m3u8',
            'resource_type' => 'playlist',
            'expires_at' => now()->subMinute(),
        ]);
        ChannelFavorite::query()->create([
            'user_id' => $user->id,
            'channel_id' => $e2eChannel->id,
        ]);
        DB::table('sessions')->insert([
            'id' => 'iptv-e2e-session',
            'user_id' => $e2eUser->id,
            'ip_address' => '198.51.100.20',
            'user_agent' => 'IPTV E2E browser',
            'payload' => '',
            'last_activity' => now()->timestamp,
        ]);
        config(['session.driver' => 'database']);

        $this->artisan('iptv:prune')
            ->expectsOutputToContain('Pruned 1')
            ->assertSuccessful();
        $this->assertDatabaseMissing('iptv_playback_sessions', ['id' => $expired->id]);

        $this->artisan('iptv:e2e:clean', [
            '--force' => true,
            '--user' => 'IPTV-E2E@EXAMPLE.TEST',
        ])
            ->expectsOutputToContain('1 provider')
            ->assertSuccessful();

        $this->assertDatabaseMissing('iptv_providers', ['id' => $e2eProvider->id]);
        $this->assertDatabaseHas('iptv_providers', ['id' => $regularProvider->id]);
        $this->assertFalse($e2eUser->fresh()->is_active);
        $this->assertNotNull($e2eUser->fresh()->disabled_at);
        $this->assertNull($e2eUser->fresh()->remember_token);
        $this->assertTrue($regularUser->fresh()->is_active);
        $this->assertDatabaseMissing('sessions', ['user_id' => $e2eUser->id]);
    }

    public function test_e2e_cleanup_refuses_to_disable_an_untagged_or_admin_user(): void
    {
        $regularUser = User::factory()->create([
            'email' => 'regular@example.test',
            'is_active' => true,
        ]);
        $admin = User::factory()->create([
            'name' => 'Administrator [E2E]',
            'email' => 'admin-e2e@example.test',
            'is_admin' => true,
            'is_active' => true,
            'preferences' => ['e2e' => true],
        ]);

        $this->artisan('iptv:e2e:clean', [
            '--force' => true,
            '--user' => $regularUser->email,
        ])->assertFailed();
        $this->artisan('iptv:e2e:clean', [
            '--force' => true,
            '--user' => $admin->email,
        ])->assertFailed();

        $this->assertTrue($regularUser->fresh()->is_active);
        $this->assertTrue($admin->fresh()->is_active);
    }
}
