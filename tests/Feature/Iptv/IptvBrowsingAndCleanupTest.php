<?php

namespace Tests\Feature\Iptv;

use App\Models\Iptv\ChannelFavorite;
use App\Models\Iptv\IptvPlaybackResource;
use App\Models\Iptv\IptvPlaybackSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

        $this->artisan('iptv:prune')
            ->expectsOutputToContain('Pruned 1')
            ->assertSuccessful();
        $this->assertDatabaseMissing('iptv_playback_sessions', ['id' => $expired->id]);

        $this->artisan('iptv:e2e:clean', ['--force' => true])
            ->expectsOutputToContain('1 provider')
            ->assertSuccessful();

        $this->assertDatabaseMissing('iptv_providers', ['id' => $e2eProvider->id]);
        $this->assertDatabaseHas('iptv_providers', ['id' => $regularProvider->id]);
    }
}
