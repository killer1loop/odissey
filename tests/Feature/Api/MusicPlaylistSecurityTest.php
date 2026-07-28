<?php

namespace Tests\Feature\Api;

use App\Models\MediaItem;
use App\Models\MusicPlaylistItem;
use App\Models\User;
use App\Services\Api\NativeTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Tests\TestCase;

class MusicPlaylistSecurityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        if (! config('app.key')) {
            config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        }
        config([
            'cache.default' => 'array',
            'odissey.runtime_cache_store' => 'array',
        ]);
        Cache::store('array')->flush();
    }

    public function test_mutation_routes_are_owner_throttled_and_tracks_are_hard_capped_at_one_thousand(): void
    {
        foreach ([
            'api.v1.music.playlists.store',
            'api.v1.music.playlists.update',
            'api.v1.music.playlists.destroy',
        ] as $name) {
            $route = app('router')->getRoutes()->getByName($name);

            $this->assertNotNull($route);
            $this->assertContains(
                'throttle:30,1,native-playlist-mutation:',
                $route->gatherMiddleware(),
            );
        }

        config(['native-client.maximum_music_playlist_tracks' => 5000]);
        $user = User::factory()->create(['is_active' => true]);
        $trackIds = array_map(
            static fn (): string => (string) Str::ulid(),
            range(1, 1001),
        );

        $this->withToken($this->token($user, 'playlist-cap'))
            ->postJson('/api/v1/music/playlists', [
                'name' => 'Too large',
                'trackIds' => $trackIds,
            ])->assertUnprocessable()
            ->assertJsonValidationErrors('trackIds');
    }

    public function test_an_idempotent_update_preserves_existing_playlist_item_rows(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $first = $this->track($user, 'First');
        $second = $this->track($user, 'Second');
        $token = $this->token($user, 'playlist-idempotence');
        $payload = [
            'name' => 'Unchanged',
            'trackIds' => [
                (string) $second->getKey(),
                (string) $first->getKey(),
            ],
        ];
        $playlistId = (string) $this->withToken($token)
            ->postJson('/api/v1/music/playlists', $payload)
            ->assertCreated()
            ->json('data.id');
        $before = MusicPlaylistItem::query()
            ->where('music_playlist_id', $playlistId)
            ->orderBy('position')
            ->pluck('id')
            ->all();

        $this->withToken($token)
            ->putJson('/api/v1/music/playlists/'.$playlistId, $payload)
            ->assertOk()
            ->assertJsonPath('data.trackCount', 2);

        $this->assertSame(
            $before,
            MusicPlaylistItem::query()
                ->where('music_playlist_id', $playlistId)
                ->orderBy('position')
                ->pluck('id')
                ->all(),
        );
    }

    public function test_a_held_user_mutation_lock_fails_fast_without_blocking_other_owners(): void
    {
        config([
            'native-client.music_playlist_lock_wait_seconds' => 0,
        ]);
        $user = User::factory()->create(['is_active' => true]);
        $other = User::factory()->create(['is_active' => true]);
        $held = Cache::store('array')->lock(
            'odissey:api:music-playlist-user:'
                .hash('sha256', (string) $user->getKey()),
            30,
        );
        $this->assertTrue($held->get());

        try {
            $this->withToken($this->token($user, 'playlist-contended'))
                ->postJson('/api/v1/music/playlists', [
                    'name' => 'Contended',
                    'trackIds' => [],
                ])->assertConflict()
                ->assertHeader('Retry-After', '1');
            $this->withToken($this->token($other, 'playlist-other-owner'))
                ->postJson('/api/v1/music/playlists', [
                    'name' => 'Independent',
                    'trackIds' => [],
                ])->assertCreated();
        } finally {
            $held->release();
        }

        $this->withToken($this->token($user, 'playlist-after-lock'))
            ->postJson('/api/v1/music/playlists', [
                'name' => 'After lock',
                'trackIds' => [],
            ])->assertCreated();
    }

    private function token(User $user, string $installation): string
    {
        return app(NativeTokenService::class)->issue($user, [
            'installationId' => $installation.'-0000000000000000',
            'deviceName' => 'Playlist Security Test',
            'platform' => 'tvOS',
            'appVersion' => '1.0',
            'osVersion' => '26.5',
        ])['accessToken'];
    }

    private function track(User $user, string $title): MediaItem
    {
        return MediaItem::query()->create([
            'user_id' => $user->getKey(),
            'title' => $title,
            'media_kind' => 'music',
            'source_type' => 'local',
            'source_locator' => '/media/'.Str::slug($title).'.m4a',
            'metadata' => ['kind' => 'track'],
        ]);
    }
}
