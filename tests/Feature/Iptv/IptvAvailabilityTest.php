<?php

namespace Tests\Feature\Iptv;

use App\Models\Iptv\ChannelFavorite;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithIptv;
use Tests\TestCase;

class IptvAvailabilityTest extends TestCase
{
    use InteractsWithIptv;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->loadIptvRoutes();
    }

    public function test_guide_and_favorites_require_an_enabled_provider_group_and_channel(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $provider = $this->makeProvider();
        $channel = $this->makeChannel($provider, [
            'name' => 'Availability invariant channel',
        ]);

        $this->actingAs($user)
            ->get(route('iptv.guide'))
            ->assertRedirect(route('iptv.channels.index', ['view' => 'guide']));

        $this->actingAs($user)
            ->get(route('iptv.channels.index'))
            ->assertOk()
            ->assertSee($channel->name);
        $this->actingAs($user)
            ->post(route('iptv.favorites.store', $channel))
            ->assertOk();
        $this->assertDatabaseHas('channel_favorites', [
            'user_id' => $user->id,
            'channel_id' => $channel->id,
        ]);

        $channel->group->update(['is_active' => false]);
        $this->actingAs($user)
            ->get(route('iptv.channels.index'))
            ->assertOk()
            ->assertDontSee($channel->name);
        $this->actingAs($user)
            ->post(route('iptv.favorites.store', $channel))
            ->assertNotFound();
        $this->actingAs($user)
            ->delete(route('iptv.favorites.destroy', $channel))
            ->assertNotFound();
        $this->assertSame(1, ChannelFavorite::query()->count());

        $channel->group->update(['is_active' => true]);
        $provider->update(['enabled' => false]);
        $this->actingAs($user)
            ->get(route('iptv.channels.index'))
            ->assertOk()
            ->assertDontSee($channel->name);
        $this->actingAs($user)
            ->post(route('iptv.favorites.store', $channel))
            ->assertNotFound();
        $this->actingAs($user)
            ->delete(route('iptv.favorites.destroy', $channel))
            ->assertNotFound();

        $provider->update(['enabled' => true]);
        $channel->update(['is_active' => false]);
        $this->actingAs($user)
            ->get(route('iptv.channels.index'))
            ->assertOk()
            ->assertDontSee($channel->name);
        $this->actingAs($user)
            ->post(route('iptv.favorites.store', $channel))
            ->assertNotFound();
        $this->actingAs($user)
            ->delete(route('iptv.favorites.destroy', $channel))
            ->assertNotFound();
        $this->assertSame(1, ChannelFavorite::query()->count());
    }
}
