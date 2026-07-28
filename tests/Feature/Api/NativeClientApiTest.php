<?php

namespace Tests\Feature\Api;

use App\Http\Middleware\AuthenticateNativeClient;
use App\Http\Middleware\AuthenticateNativePlaybackGrant;
use App\Http\Middleware\EnsureNativeClientIsAdmin;
use App\Jobs\Iptv\SyncIptvProvider;
use App\Jobs\Media\ScanMediaSource;
use App\Jobs\Media\TranscodeMediaToHls;
use App\Models\AdminAuditEvent;
use App\Models\Iptv\ChannelFavorite;
use App\Models\Iptv\EpgProgram;
use App\Models\Iptv\IptvPlaybackSession;
use App\Models\MediaFavorite;
use App\Models\MediaItem;
use App\Models\MediaSource;
use App\Models\MediaSubtitle;
use App\Models\MusicPlaylistItem;
use App\Models\NativeClientSession;
use App\Models\NativePlaybackGrant;
use App\Models\NativeRefreshTokenUse;
use App\Models\PlaybackHistory;
use App\Models\PlaybackProgress;
use App\Models\TranscodeSession;
use App\Models\User;
use App\Services\Media\TranscodeStorage;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Concerns\InteractsWithIptv;
use Tests\TestCase;

class NativeClientApiTest extends TestCase
{
    use InteractsWithIptv;
    use RefreshDatabase;

    private string $temporaryPath;

    protected function setUp(): void
    {
        parent::setUp();

        if (! config('app.key')) {
            config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        }
        $this->temporaryPath = sys_get_temp_dir()
            .'/odissey-native-api-'.Str::uuid();
        File::ensureDirectoryExists($this->temporaryPath);
        config([
            'odissey.transcode_path' => $this->temporaryPath.'/transcodes',
            'odissey.transcode_min_free_bytes' => 0,
        ]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->temporaryPath);

        parent::tearDown();
    }

    public function test_discovery_is_public_versioned_and_contains_no_secrets(): void
    {
        config([
            'odissey.release' => 'test-release',
            'odissey-auth.setup_token' => 'must-not-leak',
        ]);

        $response = $this->getJson('/.well-known/odissey')
            ->assertOk()
            ->assertHeader('X-Request-ID')
            ->assertJsonPath('product', 'Odissey')
            ->assertJsonPath('setupRequired', true)
            ->assertJsonPath('api.minimumVersion', '1')
            ->assertJsonPath('api.maximumVersion', '1');

        $this->assertStringNotContainsString(
            'must-not-leak',
            (string) $response->getContent(),
        );

        User::factory()->create();
        $this->getJson('/api/v1/server')
            ->assertOk()
            ->assertJsonPath('setupRequired', false);
    }

    public function test_native_authorization_runs_before_implicit_bindings(): void
    {
        $this->getJson('/api/v1/server')->assertOk();
        $router = app('router');
        $adminRoute = $router->getRoutes()->getByName(
            'api.v1.admin.users.update',
        );
        $grantRoute = $router->getRoutes()->getByName(
            'api.v1.playback.live.resource',
        );
        $this->assertNotNull($adminRoute);
        $this->assertNotNull($grantRoute);
        $adminMiddleware = $router->gatherRouteMiddleware($adminRoute);
        $grantMiddleware = $router->gatherRouteMiddleware($grantRoute);
        $binding = SubstituteBindings::class;
        $this->assertContains($binding, $adminMiddleware);
        $this->assertContains($binding, $grantMiddleware);

        foreach ([
            AuthenticateNativeClient::class,
            EnsureNativeClientIsAdmin::class,
        ] as $middleware) {
            $this->assertContains($middleware, $adminMiddleware);
            $this->assertLessThan(
                array_search($binding, $adminMiddleware, true),
                array_search($middleware, $adminMiddleware, true),
            );
        }
        $this->assertContains(
            AuthenticateNativePlaybackGrant::class,
            $grantMiddleware,
        );
        $this->assertLessThan(
            array_search($binding, $grantMiddleware, true),
            array_search(
                AuthenticateNativePlaybackGrant::class,
                $grantMiddleware,
                true,
            ),
        );

        $this->patchJson('/api/v1/admin/users/999999', [])
            ->assertUnauthorized();
        $viewer = User::factory()->create();
        $this->withToken($this->login($viewer, 'pre-binding-admin'))
            ->patchJson('/api/v1/admin/users/999999', [])
            ->assertForbidden();
    }

    public function test_problem_responses_echo_only_valid_request_ids(): void
    {
        $validationId = 'request.validation-123';
        $this->withHeaders(['X-Request-ID' => $validationId])
            ->postJson('/api/v1/auth/login', [])
            ->assertUnprocessable()
            ->assertHeader('X-Request-ID', $validationId)
            ->assertJsonPath('requestId', $validationId);

        $viewer = User::factory()->create();
        $token = $this->login($viewer, 'problem-request-id');
        $forbiddenId = 'request.forbidden-123';
        $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
            'X-Request-ID' => $forbiddenId,
        ])->getJson('/api/v1/admin/users')
            ->assertForbidden()
            ->assertHeader('X-Request-ID', $forbiddenId)
            ->assertJsonPath('requestId', $forbiddenId);
        $notFoundId = 'request.not-found-123';
        $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
            'X-Request-ID' => $notFoundId,
        ])->getJson('/api/v1/media/does-not-exist')
            ->assertNotFound()
            ->assertHeader('X-Request-ID', $notFoundId)
            ->assertJsonPath('requestId', $notFoundId);

        $response = $this->withHeaders(['X-Request-ID' => 'invalid id'])
            ->getJson('/api/v1/does-not-exist')
            ->assertNotFound();
        $generated = (string) $response->headers->get('X-Request-ID');
        $this->assertNotSame('invalid id', $generated);
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f-]{36}$/',
            $generated,
        );
        $response->assertJsonPath('requestId', $generated);
    }

    public function test_initial_setup_requires_confirmed_password_and_returns_the_canonical_auth_shape(): void
    {
        $payload = [
            'name' => 'Initial Admin',
            'email' => 'initial-admin@example.test',
            'password' => 'VeryStrong!123',
            'device' => $this->device('initial-admin'),
        ];
        $this->postJson('/api/v1/setup/admin', $payload)
            ->assertUnprocessable()
            ->assertJsonPath('code', 'validation_failed')
            ->assertJsonValidationErrors('passwordConfirmation');

        $payload['passwordConfirmation'] = $payload['password'];
        $response = $this->postJson('/api/v1/setup/admin', $payload)
            ->assertCreated()
            ->assertJsonPath('user.email', $payload['email'])
            ->assertJsonPath('profiles.0.active', true)
            ->assertJsonStructure([
                'sessionId',
                'accessToken',
                'refreshToken',
                'tokenType',
                'expiresIn',
                'accessExpiresAt',
                'refreshExpiresAt',
                'user',
                'profiles',
                'activeProfile',
            ]);
        $this->assertSame(
            $response->json('user.id'),
            $response->json('activeProfile'),
        );
        $this->assertTrue(User::query()->sole()->is_admin);
    }

    public function test_login_uses_hashed_rotating_tokens_and_refresh_replay_revokes_the_device(): void
    {
        $user = User::factory()->create([
            'email' => 'viewer@example.test',
            'password' => 'VeryStrong!123',
            'is_active' => true,
        ]);
        $login = $this->postJson('/api/v1/auth/login', [
            'email' => 'VIEWER@EXAMPLE.TEST',
            'password' => 'VeryStrong!123',
            'device' => $this->device(),
        ])->assertOk()
            ->assertJsonPath('user.id', (string) $user->id)
            ->assertJsonPath('profiles.0.id', (string) $user->id)
            ->assertJsonPath('activeProfile', (string) $user->id)
            ->assertJsonPath('tokenType', 'Bearer')
            ->assertJsonStructure([
                'sessionId',
                'accessToken',
                'refreshToken',
                'tokenType',
                'expiresIn',
                'accessExpiresAt',
                'refreshExpiresAt',
                'user',
                'profiles',
                'activeProfile',
            ]);
        $access = (string) $login->json('accessToken');
        $refresh = (string) $login->json('refreshToken');
        $session = NativeClientSession::query()->sole();

        $this->assertNotSame($access, $session->access_token_hash);
        $this->assertNotSame($refresh, $session->refresh_token_hash);
        $this->assertSame(hash('sha256', $access), $session->access_token_hash);
        $this->assertSame(hash('sha256', $refresh), $session->refresh_token_hash);

        $this->withToken($access)->getJson('/api/v1/me')
            ->assertOk()
            ->assertJsonPath('data.email', 'viewer@example.test');

        $rotation = $this->postJson('/api/v1/auth/refresh', [
            'refreshToken' => $refresh,
        ])->assertOk();
        $newAccess = (string) $rotation->json('accessToken');
        $newRefresh = (string) $rotation->json('refreshToken');
        $this->assertNotSame($access, $newAccess);
        $this->assertNotSame($refresh, $newRefresh);

        $this->withToken($access)->getJson('/api/v1/me')
            ->assertUnauthorized()
            ->assertHeader('Content-Type', 'application/problem+json');
        $this->withToken($newAccess)->getJson('/api/v1/me')->assertOk();

        $this->postJson('/api/v1/auth/refresh', [
            'refreshToken' => $refresh,
        ])->assertUnauthorized()
            ->assertJsonPath('code', 'authentication_required');

        $this->assertNotNull($session->refresh()->revoked_at);
        $this->withToken($newAccess)->getJson('/api/v1/me')
            ->assertUnauthorized();
    }

    public function test_replaying_any_older_refresh_generation_revokes_the_device_family(): void
    {
        $user = User::factory()->create([
            'email' => 'rotation@example.test',
            'password' => 'VeryStrong!123',
            'is_active' => true,
        ]);
        $login = $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'VeryStrong!123',
            'device' => $this->device('rotation'),
        ])->assertOk();
        $firstRefresh = (string) $login->json('refreshToken');
        $second = $this->postJson('/api/v1/auth/refresh', [
            'refreshToken' => $firstRefresh,
        ])->assertOk();
        $third = $this->postJson('/api/v1/auth/refresh', [
            'refreshToken' => (string) $second->json('refreshToken'),
        ])->assertOk();

        $this->assertDatabaseCount('native_refresh_token_uses', 2);
        $this->assertSame(
            2,
            NativeRefreshTokenUse::query()->distinct('token_hash')->count(),
        );
        $this->assertArrayNotHasKey(
            'token_hash',
            NativeRefreshTokenUse::query()->firstOrFail()->toArray(),
        );

        $this->postJson('/api/v1/auth/refresh', [
            'refreshToken' => $firstRefresh,
        ])->assertUnauthorized();
        $this->withToken((string) $third->json('accessToken'))
            ->getJson('/api/v1/me')
            ->assertUnauthorized();
        $this->assertNotNull(
            NativeClientSession::query()->sole()->revoked_at,
        );
    }

    public function test_random_refresh_token_for_a_real_session_cannot_revoke_it(): void
    {
        $user = User::factory()->create([
            'email' => 'random-refresh@example.test',
            'password' => 'VeryStrong!123',
            'is_active' => true,
        ]);
        $login = $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'VeryStrong!123',
            'device' => $this->device('random-refresh'),
        ])->assertOk();
        $refresh = (string) $login->json('refreshToken');
        [, $sessionId] = explode('.', $refresh, 3);

        $this->postJson('/api/v1/auth/refresh', [
            'refreshToken' => 'od_rt.'.$sessionId.'.'.Str::random(64),
        ])->assertUnauthorized();

        $this->assertNull(
            NativeClientSession::query()->sole()->revoked_at,
        );
        $this->postJson('/api/v1/auth/refresh', [
            'refreshToken' => $refresh,
        ])->assertOk();
    }

    public function test_invalid_and_disabled_logins_have_the_same_non_enumerating_shape(): void
    {
        User::factory()->create([
            'email' => 'disabled@example.test',
            'password' => 'VeryStrong!123',
            'is_active' => false,
        ]);
        $unknown = $this->postJson('/api/v1/auth/login', [
            'email' => 'unknown@example.test',
            'password' => 'VeryStrong!123',
            'device' => $this->device('unknown'),
        ])->assertUnauthorized();
        $disabled = $this->postJson('/api/v1/auth/login', [
            'email' => 'disabled@example.test',
            'password' => 'VeryStrong!123',
            'device' => $this->device('disabled'),
        ])->assertUnauthorized();

        $this->assertSame(
            Arr::except($unknown->json(), 'requestId'),
            Arr::except($disabled->json(), 'requestId'),
        );
        $this->assertNotSame(
            $unknown->json('requestId'),
            $disabled->json('requestId'),
        );
        $this->assertSame(
            $unknown->json('requestId'),
            $unknown->headers->get('X-Request-ID'),
        );
        $this->assertSame(
            $disabled->json('requestId'),
            $disabled->headers->get('X-Request-ID'),
        );
        $this->assertSame(
            'application/problem+json',
            $disabled->headers->get('Content-Type'),
        );
    }

    public function test_catalog_is_paginated_scoped_and_never_serializes_source_secrets(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $first = $this->mediaItem($user, 'Alpha', [
            'kind' => 'movie',
            'overview' => 'Safe overview',
            'poster_url' => 'https://provider.test/private-token/poster.jpg',
            'xtream_stream_id' => 'provider-secret-id',
        ]);
        $second = $this->mediaItem($user, 'Beta', ['kind' => 'movie']);
        $this->mediaItem($other, 'Other private item', ['kind' => 'movie']);
        MediaFavorite::query()->create([
            'user_id' => $user->id,
            'media_item_id' => $first->id,
        ]);
        PlaybackProgress::query()->create([
            'user_id' => $user->id,
            'media_item_id' => $second->id,
            'position_ms' => 30_000,
            'duration_ms' => 60_000,
            'sequence' => 1,
        ]);
        $token = $this->login($user);

        $home = $this->withToken($token)->getJson('/api/v1/home')
            ->assertOk()
            ->assertJsonFragment(['title' => 'Alpha'])
            ->assertJsonFragment(['title' => 'Beta'])
            ->assertJsonMissing(['title' => 'Other private item']);
        $this->assertStringNotContainsString(
            'private-token',
            (string) $home->getContent(),
        );
        $this->assertStringNotContainsString(
            'provider-secret-id',
            (string) $home->getContent(),
        );

        $page = $this->withToken($token)->getJson(
            '/api/v1/libraries/movies/items?limit=1',
        )->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonStructure(['page' => ['nextCursor']]);
        $this->assertNotNull($page->json('page.nextCursor'));
        $this->withToken($token)
            ->getJson('/api/v1/media/'.$first->id)
            ->assertOk()
            ->assertJsonPath('data.summary.overview', 'Safe overview');

        $otherToken = $this->login($other, 'other-device');
        $this->withToken($otherToken)
            ->getJson('/api/v1/media/'.$first->id)
            ->assertNotFound();
    }

    public function test_tv_library_contains_series_only_and_music_summaries_are_cursor_paginated(): void
    {
        $user = User::factory()->create();
        $series = $this->mediaItem($user, 'Example Series', [
            'kind' => 'series',
            'series_title' => 'Example Series',
        ]);
        $episodeOne = $this->mediaItem($user, 'Episode One', [
            'kind' => 'episode',
            'series_title' => 'Example Series',
            'season_number' => 1,
            'episode_number' => 1,
        ]);
        $episodeTwo = $this->mediaItem($user, 'Episode Two', [
            'kind' => 'episode',
            'series_title' => 'Example Series',
            'season_number' => 1,
            'episode_number' => 2,
        ]);
        foreach ([
            ['Artist A', 'Album A', 'Track A', 2024, 1],
            ['Artist B', 'Album B', 'Track B', 2025, 2],
            ['Artist B', 'Album B', 'Z First Track', 2025, 1],
            ['Artist B', 'Album Z', 'Album Z Track', 2025, 1],
            ['Artist C', 'Album C', 'Track C', 2026, 1],
        ] as [$artist, $album, $title, $year, $trackNumber]) {
            $this->mediaItem($user, $title, [
                'kind' => 'track',
                'artist' => $artist,
                'album' => $album,
                'year' => $year,
                'disc_number' => 1,
                'track_number' => $trackNumber,
            ], [
                'media_kind' => 'music',
                'video_codec' => null,
            ]);
        }
        $token = $this->login($user, 'catalog-shapes');

        $tv = $this->withToken($token)
            ->getJson('/api/v1/libraries/tv/items')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', (string) $series->id);
        $this->assertStringNotContainsString(
            'Episode One',
            (string) $tv->getContent(),
        );
        $this->withToken($token)
            ->getJson('/api/v1/libraries')
            ->assertOk()
            ->assertJsonPath('data.1.itemCount', 1);
        $season = $this->withToken($token)
            ->getJson('/api/v1/series/'.$series->id.'/seasons')
            ->assertOk()
            ->assertJsonPath('data.0.id', $series->id.':1');
        $episodes = $this->withToken($token)
            ->getJson('/api/v1/seasons/'
                .urlencode((string) $season->json('data.0.id'))
                .'/episodes?limit=1')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', (string) $episodeOne->id)
            ->assertJsonStructure([
                'page' => ['perPage', 'nextCursor', 'previousCursor'],
            ]);
        $this->assertNotNull($episodes->json('page.nextCursor'));
        $this->withToken($token)
            ->getJson('/api/v1/seasons/'
                .urlencode((string) $season->json('data.0.id'))
                .'/episodes?limit=1&cursor='
                .urlencode((string) $episodes->json('page.nextCursor')))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', (string) $episodeTwo->id);

        $artists = $this->withToken($token)
            ->getJson('/api/v1/music/artists?limit=2')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonStructure([
                'data' => [['id', 'name', 'trackCount', 'artworkUrl']],
                'page' => ['perPage', 'nextCursor', 'previousCursor'],
            ]);
        $this->assertNotNull($artists->json('page.nextCursor'));
        $this->withToken($token)->getJson(
            '/api/v1/music/artists?limit=2&cursor='
                .urlencode((string) $artists->json('page.nextCursor')),
        )->assertOk()->assertJsonCount(1, 'data');
        $this->withToken($token)
            ->getJson('/api/v1/music/albums?artist=Artist%20B&limit=1')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Album B')
            ->assertJsonPath('data.0.year', 2025)
            ->assertJsonPath('data.0.trackCount', 2);
        $this->withToken($token)
            ->getJson(
                '/api/v1/music/tracks?artist=Artist%20B&album=Album%20B',
            )->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.title', 'Z First Track')
            ->assertJsonPath('data.1.title', 'Track B');
        $this->withToken($token)
            ->getJson('/api/v1/music/artists?limit=101')
            ->assertUnprocessable();
    }

    public function test_synthetic_unknown_music_summaries_can_be_drilled_into(): void
    {
        $user = User::factory()->create();
        $track = $this->mediaItem($user, 'Unattributed Track', [
            'kind' => 'track',
        ], [
            'media_kind' => 'music',
            'video_codec' => null,
        ]);
        $token = $this->login($user, 'unknown-music');

        $this->withToken($token)
            ->getJson('/api/v1/music/artists')
            ->assertOk()
            ->assertJsonPath('data.0.name', 'Unknown Artist')
            ->assertJsonPath('data.0.trackCount', 1);
        $this->withToken($token)
            ->getJson('/api/v1/music/albums?artist=Unknown%20Artist')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Unknown Album');
        $this->withToken($token)
            ->getJson(
                '/api/v1/music/tracks?artist=Unknown%20Artist'
                    .'&album=Unknown%20Album',
            )
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', (string) $track->id);
    }

    public function test_music_playlists_validate_tracks_normalize_names_and_remain_owner_scoped(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $first = $this->mediaItem($user, 'First Track', [
            'kind' => 'track',
            'artist' => 'Artist',
        ], [
            'media_kind' => 'music',
            'video_codec' => null,
        ]);
        $second = $this->mediaItem($user, 'Second Track', [
            'kind' => 'track',
            'artist' => 'Artist',
        ], [
            'media_kind' => 'music',
            'video_codec' => null,
        ]);
        $third = $this->mediaItem($user, 'Third Track', [
            'kind' => 'track',
            'artist' => 'Artist',
        ], [
            'media_kind' => 'music',
            'video_codec' => null,
        ]);
        $video = $this->mediaItem($user, 'Not Music', ['kind' => 'movie']);
        $privateTrack = $this->mediaItem($other, 'Other Track', [
            'kind' => 'track',
        ], [
            'media_kind' => 'music',
            'video_codec' => null,
        ]);
        $token = $this->login($user, 'playlist-owner');

        $this->withToken($token)
            ->postJson('/api/v1/music/playlists', [
                'name' => '   ',
                'trackIds' => [],
            ])->assertUnprocessable()
            ->assertJsonValidationErrors('name');
        foreach ([$video, $privateTrack] as $invalidTrack) {
            $this->withToken($token)
                ->postJson('/api/v1/music/playlists', [
                    'name' => 'Invalid '.$invalidTrack->title,
                    'trackIds' => [(string) $invalidTrack->id],
                ])->assertUnprocessable()
                ->assertJsonValidationErrors('trackIds');
        }

        $created = $this->withToken($token)
            ->postJson('/api/v1/music/playlists', [
                'name' => '  Road   Trip  ',
                'trackIds' => [
                    (string) $second->id,
                    (string) $first->id,
                ],
            ])->assertCreated()
            ->assertJsonPath('data.name', 'Road Trip')
            ->assertJsonPath('data.trackCount', 2)
            ->assertJsonPath('data.items.0.position', 0)
            ->assertJsonPath('data.items.0.track.id', (string) $second->id)
            ->assertJsonPath('data.items.1.position', 1)
            ->assertJsonPath('data.items.1.track.id', (string) $first->id)
            ->assertJsonStructure([
                'data' => [
                    'id',
                    'itemsPage' => [
                        'perPage',
                        'nextCursor',
                        'previousCursor',
                    ],
                ],
            ]);
        $playlistId = (string) $created->json('data.id');
        $this->assertDatabaseHas('music_playlists', [
            'id' => $playlistId,
            'user_id' => $user->id,
            'name' => 'Road Trip',
            'normalized_name' => 'road trip',
        ]);
        $this->assertSame(
            [
                (string) $second->id,
                (string) $first->id,
            ],
            MusicPlaylistItem::query()
                ->where('music_playlist_id', $playlistId)
                ->orderBy('position')
                ->pluck('media_item_id')
                ->all(),
        );

        $this->withToken($token)
            ->postJson('/api/v1/music/playlists', [
                'name' => 'road trip',
                'trackIds' => [],
            ])->assertUnprocessable()
            ->assertJsonValidationErrors('name');
        $this->withToken($token)
            ->putJson('/api/v1/music/playlists/'.$playlistId, [
                'name' => 'Evening',
                'trackIds' => [
                    (string) $third->id,
                    (string) $second->id,
                ],
            ])->assertOk()
            ->assertJsonPath('data.items.0.track.id', (string) $third->id)
            ->assertJsonPath('data.items.1.track.id', (string) $second->id);

        $otherToken = $this->login($other, 'playlist-other');
        $this->withToken($otherToken)
            ->getJson('/api/v1/music/playlists/'.$playlistId)
            ->assertNotFound();
        $this->withToken($otherToken)
            ->putJson('/api/v1/music/playlists/'.$playlistId, [
                'name' => 'Stolen',
                'trackIds' => [],
            ])->assertNotFound();
        $this->withToken($otherToken)
            ->deleteJson('/api/v1/music/playlists/'.$playlistId, [
                'confirmation' => 'delete-playlist',
            ])->assertNotFound();
        $this->withToken($otherToken)
            ->getJson('/api/v1/music/playlists')
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->withToken($token)
            ->deleteJson('/api/v1/music/playlists/'.$playlistId)
            ->assertUnprocessable();
        $this->assertDatabaseHas('music_playlists', ['id' => $playlistId]);
        $this->withToken($token)
            ->deleteJson('/api/v1/music/playlists/'.$playlistId, [
                'confirmation' => 'delete-playlist',
            ])->assertOk()
            ->assertJsonPath('deleted', true);
        $this->assertDatabaseMissing('music_playlists', ['id' => $playlistId]);
        $this->assertDatabaseMissing('music_playlist_items', [
            'music_playlist_id' => $playlistId,
        ]);
    }

    public function test_music_playlist_and_item_lists_use_independent_bounded_cursors(): void
    {
        $user = User::factory()->create();
        $trackIds = [];
        foreach (range(1, 101) as $number) {
            $trackIds[] = (string) $this->mediaItem(
                $user,
                sprintf('Track %03d', $number),
                [
                    'kind' => 'track',
                    'track_number' => $number,
                ],
                [
                    'media_kind' => 'music',
                    'video_codec' => null,
                ],
            )->id;
        }
        $token = $this->login($user, 'playlist-pagination');
        $large = $this->withToken($token)
            ->postJson('/api/v1/music/playlists', [
                'name' => 'Large',
                'trackIds' => $trackIds,
            ])->assertCreated()
            ->assertJsonPath('data.trackCount', 101)
            ->assertJsonCount(50, 'data.items');
        $this->assertNotNull($large->json('data.itemsPage.nextCursor'));
        $largeId = (string) $large->json('data.id');

        foreach (['Alpha', 'Zulu'] as $name) {
            $this->withToken($token)
                ->postJson('/api/v1/music/playlists', [
                    'name' => $name,
                    'trackIds' => [],
                ])->assertCreated();
        }
        $firstPlaylistPage = $this->withToken($token)
            ->getJson('/api/v1/music/playlists?limit=2')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.name', 'Alpha')
            ->assertJsonPath('data.1.name', 'Large');
        $this->assertNotNull(
            $firstPlaylistPage->json('page.nextCursor'),
        );
        $this->withToken($token)
            ->getJson('/api/v1/music/playlists?limit=2&cursor='
                .urlencode((string) $firstPlaylistPage->json(
                    'page.nextCursor',
                )))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Zulu');

        $firstItemPage = $this->withToken($token)
            ->getJson('/api/v1/music/playlists/'.$largeId.'?limit=100')
            ->assertOk()
            ->assertJsonCount(100, 'data.items')
            ->assertJsonPath('data.items.0.track.id', $trackIds[0])
            ->assertJsonPath('data.items.99.track.id', $trackIds[99]);
        $this->assertNotNull(
            $firstItemPage->json('data.itemsPage.nextCursor'),
        );
        $this->withToken($token)
            ->getJson('/api/v1/music/playlists/'.$largeId.'?limit=100&cursor='
                .urlencode((string) $firstItemPage->json(
                    'data.itemsPage.nextCursor',
                )))
            ->assertOk()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.position', 100)
            ->assertJsonPath('data.items.0.track.id', $trackIds[100]);
    }

    public function test_openapi_boolean_query_values_apply_favorite_filters(): void
    {
        $user = User::factory()->create();
        $favoriteMovie = $this->mediaItem(
            $user,
            'Favorite Movie',
            ['kind' => 'movie'],
        );
        $this->mediaItem($user, 'Other Movie', ['kind' => 'movie']);
        $favoriteTrack = $this->mediaItem($user, 'Favorite Track', [
            'kind' => 'track',
            'artist' => 'Favorite Artist',
            'album' => 'Favorite Album',
        ], [
            'media_kind' => 'music',
            'video_codec' => null,
        ]);
        $this->mediaItem($user, 'Other Track', [
            'kind' => 'track',
            'artist' => 'Other Artist',
            'album' => 'Other Album',
        ], [
            'media_kind' => 'music',
            'video_codec' => null,
        ]);
        MediaFavorite::query()->create([
            'user_id' => $user->id,
            'media_item_id' => $favoriteMovie->id,
        ]);
        MediaFavorite::query()->create([
            'user_id' => $user->id,
            'media_item_id' => $favoriteTrack->id,
        ]);
        $provider = $this->makeProvider();
        $favoriteChannel = $this->makeChannel($provider);
        $otherChannel = $favoriteChannel->replicate();
        $otherChannel->external_id = '102';
        $otherChannel->epg_channel_id = 'news.102';
        $otherChannel->name = 'Other News';
        $otherChannel->channel_number = '2';
        $otherChannel->save();
        ChannelFavorite::query()->create([
            'user_id' => $user->id,
            'channel_id' => $favoriteChannel->id,
        ]);
        $token = $this->login($user, 'boolean-filters');

        $this->withToken($token)
            ->getJson('/api/v1/libraries/movies/items?favorite=true')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', (string) $favoriteMovie->id);
        $this->withToken($token)
            ->getJson('/api/v1/music/tracks?favorite=true')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', (string) $favoriteTrack->id);
        $this->withToken($token)
            ->getJson('/api/v1/live/channels?favorites=true')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath(
                'data.0.id',
                (string) $favoriteChannel->id,
            );
        $this->withToken($token)
            ->getJson('/api/v1/live/guide?favorites=true')
            ->assertOk()
            ->assertJsonCount(1, 'channels');
        $this->withToken($token)
            ->getJson('/api/v1/live/channels?favorites=not-a-boolean')
            ->assertUnprocessable();
    }

    public function test_library_sorting_is_validated_and_cursor_deterministic(): void
    {
        $user = User::factory()->create();
        $older = $this->mediaItem($user, 'Alphabetically First', [
            'kind' => 'movie',
            'year' => 2000,
            'release_date' => '2000-01-01',
        ]);
        $newer = $this->mediaItem($user, 'Alphabetically Last', [
            'kind' => 'movie',
            'year' => 2026,
            'release_date' => '2026-01-01',
        ]);
        $older->forceFill(['created_at' => now()->subDay()])->save();
        $newer->forceFill(['created_at' => now()])->save();
        $token = $this->login($user, 'catalog-sort');

        $this->withToken($token)
            ->getJson(
                '/api/v1/libraries/movies/items?sort=release_date&limit=1',
            )->assertOk()
            ->assertJsonPath('data.0.id', (string) $newer->id)
            ->assertJsonPath('page.perPage', 1);
        $this->withToken($token)
            ->getJson(
                '/api/v1/libraries/movies/items?sort=recently_added&limit=1',
            )->assertOk()
            ->assertJsonPath('data.0.id', (string) $newer->id);
        $this->withToken($token)
            ->getJson('/api/v1/libraries/movies/items?sort=unsupported')
            ->assertUnprocessable();
    }

    public function test_progress_updates_are_monotonic_and_user_scoped(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $item = $this->mediaItem($user, 'Progress movie', ['kind' => 'movie']);
        $token = $this->login($user);

        $this->withToken($token)
            ->putJson('/api/v1/media/'.$item->id.'/progress', [
                'sequence' => 2,
                'positionMs' => 20_000,
                'durationMs' => 60_000,
            ])->assertOk()
            ->assertJsonPath('accepted', true);
        $this->withToken($token)
            ->putJson('/api/v1/media/'.$item->id.'/progress', [
                'sequence' => 1,
                'positionMs' => 5_000,
                'durationMs' => 60_000,
            ])->assertOk()
            ->assertJsonPath('accepted', false)
            ->assertJsonPath('positionMs', 20_000);
        foreach (range(3, 30) as $sequence) {
            $this->withToken($token)
                ->putJson('/api/v1/media/'.$item->id.'/progress', [
                    'sequence' => $sequence,
                    'positionMs' => 20_000 + ($sequence * 500),
                    'durationMs' => 60_000,
                ])->assertOk();
        }
        $this->assertSame(
            1,
            PlaybackHistory::query()
                ->where('user_id', $user->id)
                ->where('media_item_id', $item->id)
                ->where('event', 'progress')
                ->count(),
        );

        $this->withToken($this->login($other, 'other-progress'))
            ->putJson('/api/v1/media/'.$item->id.'/progress', [
                'sequence' => 1,
                'positionMs' => 10_000,
            ])->assertNotFound();
        $this->assertDatabaseHas('playback_progress', [
            'user_id' => $user->id,
            'media_item_id' => $item->id,
            'position_ms' => 35_000,
        ]);
    }

    public function test_playback_resolution_direct_plays_tvos_compatible_media_with_a_hashed_grant(): void
    {
        $user = User::factory()->create();
        $path = $this->temporaryPath.'/compatible.mp4';
        File::put($path, 'compatible-media');
        $item = $this->mediaItem($user, 'Compatible HEVC', [
            'kind' => 'movie',
            'technical' => ['width' => 3840, 'height' => 2160],
        ], [
            'source_locator' => $path,
            'container' => 'mp4',
            'video_codec' => 'hevc',
            'audio_codec' => 'eac3',
            'mime_type' => 'video/mp4',
            'requires_transcode' => true,
            'size_bytes' => strlen('compatible-media'),
        ]);
        $token = $this->login($user);
        $response = $this->withToken($token)
            ->postJson('/api/v1/playback/resolve', $this->resolution($item))
            ->assertOk()
            ->assertJsonPath('mode', 'direct')
            ->assertJsonPath('status', 'ready');
        $url = (string) $response->json('url');
        $grant = NativePlaybackGrant::query()->sole();
        $grantToken = explode('/', parse_url($url, PHP_URL_PATH))[6] ?? '';

        $this->assertNotSame($grantToken, $grant->token_hash);
        $this->assertSame(hash('sha256', $grantToken), $grant->token_hash);
        $this->get($url)
            ->assertOk()
            ->assertHeader('Content-Type', 'video/mp4');

        $grant->forceFill(['expires_at' => now()->subSecond()])->save();
        $this->get($url)
            ->assertUnauthorized()
            ->assertJsonPath('code', 'authentication_required');
    }

    public function test_playback_grants_have_a_hard_ten_minute_window_and_rotate_per_client_resource(): void
    {
        $this->travelTo(now()->startOfSecond());
        config([
            'native-client.playback_grant_minutes' => 240,
            'native-client.playback_renewal_minutes' => 240,
        ]);
        $user = User::factory()->create();
        $firstPath = $this->temporaryPath.'/rotation-first.mp4';
        $secondPath = $this->temporaryPath.'/rotation-second.mp4';
        File::put($firstPath, 'first-media');
        File::put($secondPath, 'second-media');
        $firstItem = $this->mediaItem(
            $user,
            'Rotation first',
            ['kind' => 'movie'],
            [
                'source_locator' => $firstPath,
                'size_bytes' => strlen('first-media'),
            ],
        );
        $secondItem = $this->mediaItem(
            $user,
            'Rotation second',
            ['kind' => 'movie'],
            [
                'source_locator' => $secondPath,
                'size_bytes' => strlen('second-media'),
            ],
        );
        $livingRoomToken = $this->login($user, 'rotation-living-room');
        $firstResolution = $this->withToken($livingRoomToken)
            ->postJson(
                '/api/v1/playback/resolve',
                $this->resolution($firstItem),
            )->assertOk();
        $firstGrant = NativePlaybackGrant::query()
            ->findOrFail($firstResolution->json('sessionId'));

        $this->assertSame(
            now()->addMinutes(10)->utc()->toIso8601String(),
            $firstResolution->json('expiresAt'),
        );

        $secondResolution = $this->withToken($livingRoomToken)
            ->postJson(
                '/api/v1/playback/resolve',
                $this->resolution($secondItem),
            )->assertOk();
        $secondGrant = NativePlaybackGrant::query()
            ->findOrFail($secondResolution->json('sessionId'));
        $firstGrant->refresh();
        $this->assertNull($firstGrant->revoked_at);

        $bedroomToken = $this->login($user, 'rotation-bedroom');
        $bedroomResolution = $this->withToken($bedroomToken)
            ->postJson(
                '/api/v1/playback/resolve',
                $this->resolution($firstItem),
            )->assertOk();
        $bedroomGrant = NativePlaybackGrant::query()
            ->findOrFail($bedroomResolution->json('sessionId'));
        $firstGrant->refresh();
        $this->assertNull($firstGrant->revoked_at);

        $replacement = $this->withToken($livingRoomToken)
            ->postJson(
                '/api/v1/playback/resolve',
                $this->resolution($firstItem),
            )->assertOk();
        $replacementGrant = NativePlaybackGrant::query()
            ->findOrFail($replacement->json('sessionId'));
        $firstGrant->refresh();
        $secondGrant->refresh();
        $bedroomGrant->refresh();

        $this->assertNotNull($firstGrant->revoked_at);
        $this->assertTrue($firstGrant->expires_at->lte(now()));
        $this->assertNull($secondGrant->revoked_at);
        $this->assertNull($bedroomGrant->revoked_at);
        $this->assertNull($replacementGrant->revoked_at);
        $this->get((string) $firstResolution->json('url'))
            ->assertUnauthorized();
        $this->get((string) $secondResolution->json('url'))->assertOk();
        $this->get((string) $bedroomResolution->json('url'))->assertOk();
        $this->get((string) $replacement->json('url'))->assertOk();
    }

    public function test_resolution_uses_video_copy_for_remux_and_audio_only_transcode(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $remux = $this->mediaItem($user, 'Compatible MKV', ['kind' => 'movie'], [
            'container' => 'mkv',
            'video_codec' => 'h264',
            'audio_codec' => 'aac',
            'requires_transcode' => true,
        ]);
        $audio = $this->mediaItem($user, 'DTS MKV', ['kind' => 'movie'], [
            'container' => 'mkv',
            'video_codec' => 'hevc',
            'audio_codec' => 'dts',
            'requires_transcode' => true,
        ]);
        $token = $this->login($user);

        $this->withToken($token)
            ->postJson('/api/v1/playback/resolve', $this->resolution($remux))
            ->assertOk()
            ->assertJsonPath('mode', 'remux');
        $this->withToken($token)
            ->postJson('/api/v1/playback/resolve', $this->resolution($audio))
            ->assertOk()
            ->assertJsonPath('mode', 'audioTranscode');

        $this->assertSame(
            ['audioTranscode', 'remux'],
            TranscodeSession::query()
                ->orderBy('delivery_mode')
                ->pluck('delivery_mode')
                ->all(),
        );
        Queue::assertPushed(TranscodeMediaToHls::class, 2);
    }

    public function test_selected_tracks_are_validated_persisted_and_exposed_through_a_grant_scoped_hls_master(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $captionPath = $this->temporaryPath.'/english.vtt';
        File::put($captionPath, "WEBVTT\n\n00:00.000 --> 00:01.000\nHello\n");
        $item = $this->mediaItem($user, 'Captioned movie', [
            'kind' => 'movie',
            'technical' => [
                'subtitle_tracks' => [[
                    'codec' => 'subrip',
                    'language' => 'it',
                    'title' => 'Italiano',
                ]],
            ],
        ]);
        $caption = MediaSubtitle::query()->create([
            'media_item_id' => $item->id,
            'provider' => 'manual',
            'external_id' => 'manual-english',
            'language' => 'en',
            'label' => 'English',
            'path' => $captionPath,
            'hearing_impaired' => false,
            'metadata' => [],
        ]);
        $token = $this->login($user, 'selected-tracks');
        $request = $this->resolution($item);
        $request['audioTrackId'] = '0';
        $request['subtitleTrackId'] = 'caption:'.$caption->id;
        $response = $this->withToken($token)
            ->postJson('/api/v1/playback/resolve', $request)
            ->assertOk()
            ->assertJsonPath('mode', 'remux')
            ->assertJsonPath('selectedAudioTrackId', '0')
            ->assertJsonPath(
                'availableSubtitleTracks.0.codec',
                'subrip',
            )
            ->assertJsonPath(
                'availableSubtitleTracks.1.codec',
                'webvtt',
            )
            ->assertJsonPath(
                'selectedSubtitleTrackId',
                'caption:'.$caption->id,
            );
        $this->withToken($token)
            ->getJson('/api/v1/media/'.$item->id.'/captions')
            ->assertOk()
            ->assertJsonPath('data.0.codec', 'webvtt');
        $transcode = TranscodeSession::query()->sole();
        $this->assertSame(0, $transcode->audio_track);
        $this->assertNull($transcode->subtitle_track);
        $this->assertSame(
            (string) $caption->id,
            (string) $transcode->media_subtitle_id,
        );

        $storage = app(TranscodeStorage::class);
        $storage->prepare($transcode);
        File::put(
            $storage->manifestPath($transcode),
            "#EXTM3U\n#EXT-X-MAP:URI=\"init.mp4\"\n"
                ."#EXTINF:4,\nsegment-00000.m4s\n",
        );
        File::put(
            dirname($storage->manifestPath($transcode)).'/init.mp4',
            'init',
        );
        File::put(
            dirname($storage->manifestPath($transcode))
                .'/segment-00000.m4s',
            'segment',
        );
        $transcode->forceFill([
            'status' => TranscodeSession::STATUS_READY,
            'expires_at' => now()->addHour(),
        ])->save();

        $master = $this->get((string) $response->json('url'))
            ->assertOk()
            ->assertHeader(
                'Content-Type',
                'application/vnd.apple.mpegurl',
            );
        $body = (string) $master->getContent();
        $this->assertStringContainsString(
            '#EXT-X-MEDIA:TYPE=SUBTITLES',
            $body,
        );
        $this->assertSame(
            2,
            substr_count($body, '#EXT-X-MEDIA:TYPE=SUBTITLES'),
        );
        $this->assertStringContainsString('NAME="Italiano"', $body);
        $this->assertStringContainsString('NAME="English",DEFAULT=YES', $body);
        $this->assertStringContainsString(
            '/captions/'.$caption->id.'.vtt',
            $body,
        );
        $this->assertStringNotContainsString($captionPath, $body);

        $other = TranscodeSession::query()->create([
            'user_id' => $user->id,
            'media_item_id' => $item->id,
            'status' => TranscodeSession::STATUS_READY,
            'profile' => 'auto',
            'delivery_mode' => 'remux',
        ]);
        $this->get(str_replace(
            (string) $transcode->id,
            (string) $other->id,
            (string) $response->json('url'),
        ))->assertUnauthorized();
    }

    public function test_selected_audio_track_codec_drives_the_hls_delivery_mode(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $item = $this->mediaItem($user, 'Two audio tracks', [
            'kind' => 'movie',
            'technical' => [
                'audio_tracks' => [
                    [
                        'codec' => 'aac',
                        'language' => 'en',
                        'title' => 'English',
                    ],
                    [
                        'codec' => 'dts',
                        'language' => 'it',
                        'title' => 'Italiano DTS',
                    ],
                ],
            ],
        ]);
        $token = $this->login($user, 'audio-selector');
        $request = $this->resolution($item);
        $request['audioTrackId'] = '1';

        $this->withToken($token)
            ->postJson('/api/v1/playback/resolve', $request)
            ->assertOk()
            ->assertJsonPath('mode', 'audioTranscode')
            ->assertJsonPath('selectedAudioTrackId', '1');
        $this->assertDatabaseHas('transcode_sessions', [
            'media_item_id' => $item->id,
            'delivery_mode' => 'audioTranscode',
            'audio_track' => 1,
        ]);

        $request['audioTrackId'] = '0';
        $this->withToken($token)
            ->postJson('/api/v1/playback/resolve', $request)
            ->assertOk()
            ->assertJsonPath('mode', 'remux')
            ->assertJsonPath(
                'decisionReason',
                'selected-audio-requires-hls-rendition',
            );
        $this->assertDatabaseHas('transcode_sessions', [
            'media_item_id' => $item->id,
            'delivery_mode' => 'remux',
            'audio_track' => 0,
        ]);
    }

    public function test_playback_heartbeat_uses_a_hard_ten_minute_rolling_window_and_never_outlives_the_client_session(): void
    {
        $this->travelTo(now()->startOfSecond());
        config([
            'native-client.playback_grant_minutes' => 10,
            'native-client.playback_renewal_minutes' => 60,
        ]);
        $user = User::factory()->create();
        $path = $this->temporaryPath.'/heartbeat.mp4';
        File::put($path, 'heartbeat-media');
        $item = $this->mediaItem($user, 'Heartbeat movie', [
            'kind' => 'movie',
        ], [
            'source_locator' => $path,
            'size_bytes' => strlen('heartbeat-media'),
        ]);
        $token = $this->login($user, 'heartbeat');
        $resolved = $this->withToken($token)
            ->postJson('/api/v1/playback/resolve', $this->resolution($item))
            ->assertOk();
        $grant = NativePlaybackGrant::query()->sole();
        $initialExpiry = $grant->expires_at;
        $grant->forceFill(['expires_at' => now()->addHours(2)])->save();

        $this->travel(5)->minutes();
        $renewed = $this->withToken($token)
            ->postJson(
                '/api/v1/playback/sessions/'.$grant->id.'/heartbeat',
            )->assertOk();
        $grant->refresh();

        $this->assertTrue($grant->expires_at->gt($initialExpiry));
        $this->assertSame(
            now()->addMinutes(10)->utc()->toIso8601String(),
            $grant->expires_at->utc()->toIso8601String(),
        );
        $this->assertSame(
            $grant->expires_at->utc()->toIso8601String(),
            $renewed->json('expiresAt'),
        );

        $clientSession = NativeClientSession::query()->sole();
        $clientSession->forceFill([
            'refresh_expires_at' => now()->addMinutes(8),
        ])->save();
        $this->travel(5)->minutes();
        $renewed = $this->withToken($token)
            ->postJson(
                '/api/v1/playback/sessions/'.$grant->id.'/heartbeat',
            )->assertOk();
        $grant->refresh();
        $clientSession->refresh();
        $this->assertSame(
            $clientSession->refresh_expires_at->utc()->toIso8601String(),
            $grant->expires_at->utc()->toIso8601String(),
        );
        $this->assertSame(
            $grant->expires_at->utc()->toIso8601String(),
            $renewed->json('expiresAt'),
        );

        $grant->forceFill(['expires_at' => now()->subSecond()])->save();
        $this->withToken($token)
            ->postJson(
                '/api/v1/playback/sessions/'.$grant->id.'/heartbeat',
            )->assertNotFound();
    }

    public function test_live_grant_rotation_does_not_revoke_another_channel(): void
    {
        $this->allowPublicIptvDns();
        $user = User::factory()->create();
        $provider = $this->makeProvider();
        $firstChannel = $this->makeChannel($provider);
        $secondChannel = $firstChannel->replicate();
        $secondChannel->forceFill([
            'external_id' => '102',
            'epg_channel_id' => 'news.102',
            'name' => 'Example Sports',
            'channel_number' => '2',
        ])->save();
        $token = $this->login($user, 'live-rotation');
        $device = [
            'platform' => 'tvOS',
            'osVersion' => '26.5',
        ];

        $firstResolution = $this->withToken($token)
            ->postJson('/api/v1/live/playback/resolve', [
                'channelId' => $firstChannel->id,
                'device' => $device,
            ])->assertOk();
        $firstGrant = NativePlaybackGrant::query()
            ->findOrFail($firstResolution->json('sessionId'));
        $firstPlayback = IptvPlaybackSession::query()
            ->findOrFail($firstGrant->playback_reference);

        $firstReplacement = $this->withToken($token)
            ->postJson('/api/v1/live/playback/resolve', [
                'channelId' => $firstChannel->id,
                'device' => $device,
            ])->assertOk();
        $firstReplacementGrant = NativePlaybackGrant::query()
            ->findOrFail($firstReplacement->json('sessionId'));
        $firstGrant->refresh();
        $this->assertNotNull($firstGrant->revoked_at);
        $this->assertSame(
            $firstGrant->playback_reference,
            $firstReplacementGrant->playback_reference,
        );

        $this->withToken($token)
            ->postJson(
                '/api/v1/playback/sessions/'.$firstGrant->id.'/stop',
            )->assertOk();
        $firstPlayback->refresh();
        $firstReplacementGrant->refresh();
        $this->assertContains($firstPlayback->status, ['created', 'playing']);
        $this->assertTrue($firstPlayback->expires_at->isFuture());
        $this->assertNull($firstReplacementGrant->revoked_at);

        $secondResolution = $this->withToken($token)
            ->postJson('/api/v1/live/playback/resolve', [
                'channelId' => $secondChannel->id,
                'device' => $device,
            ])->assertOk();
        $secondGrant = NativePlaybackGrant::query()
            ->findOrFail($secondResolution->json('sessionId'));
        $firstReplacementGrant->refresh();
        $this->assertNull($firstReplacementGrant->revoked_at);

        $secondReplacement = $this->withToken($token)
            ->postJson('/api/v1/live/playback/resolve', [
                'channelId' => $secondChannel->id,
                'device' => $device,
            ])->assertOk();
        $secondReplacementGrant = NativePlaybackGrant::query()
            ->findOrFail($secondReplacement->json('sessionId'));
        $secondGrant->refresh();
        $firstReplacementGrant->refresh();

        $this->assertNotNull($secondGrant->revoked_at);
        $this->assertNull($firstReplacementGrant->revoked_at);
        $this->assertNull($secondReplacementGrant->revoked_at);
    }

    public function test_live_catalog_guide_favorites_and_resolve_never_expose_provider_credentials(): void
    {
        $this->allowPublicIptvDns();
        $user = User::factory()->create();
        $provider = $this->makeProvider();
        $channel = $this->makeChannel($provider);
        EpgProgram::query()->create([
            'iptv_provider_id' => $provider->id,
            'channel_id' => $channel->id,
            'fingerprint' => hash('sha256', 'news-now'),
            'title' => 'News Now',
            'description' => 'Current headlines',
            'starts_at' => now()->subMinutes(10),
            'ends_at' => now()->addMinutes(50),
        ]);
        ChannelFavorite::query()->create([
            'user_id' => $user->id,
            'channel_id' => $channel->id,
        ]);
        $token = $this->login($user);

        $guide = $this->withToken($token)
            ->getJson('/api/v1/live/guide?favorites=1')
            ->assertOk()
            ->assertJsonFragment(['title' => 'News Now'])
            ->assertJsonFragment(['name' => 'Example News']);
        $this->assertStringNotContainsString(
            'test-user-secret',
            (string) $guide->getContent(),
        );
        $this->assertStringNotContainsString(
            'test-password-secret',
            (string) $guide->getContent(),
        );

        $playback = $this->withToken($token)
            ->postJson('/api/v1/live/playback/resolve', [
                'channelId' => $channel->id,
                'device' => [
                    'platform' => 'tvOS',
                    'osVersion' => '26.5',
                ],
            ])->assertOk()
            ->assertJsonPath('mode', 'direct')
            ->assertJsonPath('channelId', (string) $channel->id);
        $body = (string) $playback->getContent();
        $this->assertStringNotContainsString('test-user-secret', $body);
        $this->assertStringNotContainsString('test-password-secret', $body);
        $this->assertStringNotContainsString(
            'iptv.example.test',
            $body,
        );

        Http::fake([
            '*' => Http::response(
                "#EXTM3U\n#EXTINF:6,\nhttps://cdn.example.test/one.ts\n",
                200,
                ['Content-Type' => 'application/vnd.apple.mpegurl'],
            ),
        ]);
        $manifest = $this->get((string) $playback->json('url'))
            ->assertOk()
            ->assertHeader(
                'Content-Type',
                'application/vnd.apple.mpegurl',
            );
        $manifestBody = (string) $manifest->getContent();
        $this->assertStringContainsString(
            '/api/v1/playback/assets/',
            $manifestBody,
        );
        $this->assertStringNotContainsString(
            'cdn.example.test',
            $manifestBody,
        );
        $this->assertStringNotContainsString(
            'test-user-secret',
            $manifestBody,
        );
    }

    public function test_live_playback_grants_cannot_be_reused_for_another_session(): void
    {
        $this->allowPublicIptvDns();
        $user = User::factory()->create();
        $other = User::factory()->create();
        $channel = $this->makeChannel($this->makeProvider());
        $first = $this->withToken($this->login($user, 'live-first'))
            ->postJson('/api/v1/live/playback/resolve', [
                'channelId' => $channel->id,
                'device' => [
                    'platform' => 'tvOS',
                    'osVersion' => '26.5',
                ],
            ])->assertOk();
        $firstSession = IptvPlaybackSession::query()->sole();
        $sameUserSession = IptvPlaybackSession::query()->create([
            'user_id' => $user->id,
            'channel_id' => $channel->id,
            'status' => 'created',
            'expires_at' => now()->addMinutes(10),
        ]);
        $otherUserSession = IptvPlaybackSession::query()->create([
            'user_id' => $other->id,
            'channel_id' => $channel->id,
            'status' => 'created',
            'expires_at' => now()->addMinutes(10),
        ]);

        $firstUrl = (string) $first->json('url');
        $this->get(str_replace(
            (string) $firstSession->id,
            (string) $sameUserSession->id,
            $firstUrl,
        ))
            ->assertUnauthorized();
        $this->get(str_replace(
            (string) $firstSession->id,
            (string) $otherUserSession->id,
            $firstUrl,
        ))
            ->assertUnauthorized();
    }

    public function test_admin_inventory_is_permission_gated_and_secrets_are_write_only(): void
    {
        $viewer = User::factory()->create();
        $admin = User::factory()->create(['is_admin' => true]);
        $this->makeProvider();

        $this->withToken($this->login($viewer, 'viewer-admin-check'))
            ->getJson('/api/v1/admin/iptv-providers')
            ->assertForbidden()
            ->assertJsonPath('code', 'forbidden');
        $response = $this->withToken($this->login($admin, 'admin-device'))
            ->getJson('/api/v1/admin/iptv-providers')
            ->assertOk()
            ->assertJsonPath('data.0.credentialsConfigured', true);
        $body = (string) $response->getContent();
        $this->assertStringNotContainsString('test-user-secret', $body);
        $this->assertStringNotContainsString('test-password-secret', $body);
        $this->assertStringNotContainsString('iptv.example.test', $body);
    }

    public function test_admin_mutations_are_permission_gated_confirmed_and_write_only(): void
    {
        Queue::fake();
        $this->allowPublicIptvDns();
        $viewer = User::factory()->create();
        $admin = User::factory()->create(['is_admin' => true]);
        $viewerToken = $this->login($viewer, 'admin-mutation-viewer');
        $adminToken = $this->login($admin, 'admin-mutation-admin');

        $this->withToken($viewerToken)
            ->postJson('/api/v1/admin/users', [
                'name' => 'Blocked',
                'email' => 'blocked@example.test',
                'password' => 'VeryStrong!123',
                'passwordConfirmation' => 'VeryStrong!123',
            ])->assertForbidden();
        $created = $this->withToken($adminToken)
            ->postJson('/api/v1/admin/users', [
                'name' => 'TV Viewer',
                'email' => 'tv-viewer@example.test',
                'password' => 'AnotherVeryStrong!123',
                'passwordConfirmation' => 'AnotherVeryStrong!123',
            ])->assertCreated()
            ->assertJsonPath('data.email', 'tv-viewer@example.test')
            ->assertJsonStructure(['auditId']);
        $newUser = User::query()
            ->where('email', 'tv-viewer@example.test')
            ->sole();
        $this->withToken($adminToken)
            ->postJson('/api/v1/admin/users/'.$newUser->id.'/disable')
            ->assertUnprocessable();
        $this->assertTrue($newUser->refresh()->is_active);
        $disabled = $this->withToken($adminToken)
            ->postJson('/api/v1/admin/users/'.$newUser->id.'/disable', [
                'confirmation' => 'disable-user',
            ])->assertOk()
            ->assertJsonStructure(['auditId']);
        $this->assertFalse($newUser->refresh()->is_active);
        $this->assertSame(
            (string) $newUser->id,
            (string) $created->json('data.id'),
        );

        $provider = $this->withToken($adminToken)
            ->postJson('/api/v1/admin/iptv-providers', [
                'name' => 'Native Provider',
                'providerType' => 'xtream',
                'baseUrl' => 'http://iptv.example.test',
                'username' => 'native-user-secret',
                'password' => 'native-password-secret',
                'allowInsecureHttp' => true,
            ])->assertCreated()
            ->assertJsonPath('data.usernameConfigured', true)
            ->assertJsonPath('data.passwordConfigured', true)
            ->assertJsonStructure(['auditId']);
        $providerBody = (string) $provider->getContent();
        $this->assertStringNotContainsString(
            'native-user-secret',
            $providerBody,
        );
        $this->assertStringNotContainsString(
            'native-password-secret',
            $providerBody,
        );
        $this->assertStringNotContainsString(
            'iptv.example.test',
            $providerBody,
        );
        Queue::assertPushed(SyncIptvProvider::class);

        config(['odissey.local_source_roots' => [$this->temporaryPath]]);
        $source = $this->withToken($adminToken)
            ->postJson('/api/v1/admin/media-sources', [
                'name' => 'Native Local',
                'type' => 'local',
                'path' => $this->temporaryPath,
            ])->assertCreated()
            ->assertJsonPath('data.type', MediaSource::TYPE_LOCAL)
            ->assertJsonStructure(['auditId']);
        $this->assertStringNotContainsString(
            $this->temporaryPath,
            (string) $source->getContent(),
        );
        Queue::assertPushed(ScanMediaSource::class);

        $settings = $this->withToken($adminToken)
            ->putJson('/api/v1/admin/integrations', [
                'tmdbApiToken' => 'tmdb-write-only-secret',
                'captionLanguages' => ['en', 'it'],
            ])->assertOk()
            ->assertJsonStructure(['auditId']);
        $this->assertStringNotContainsString(
            'tmdb-write-only-secret',
            (string) $settings->getContent(),
        );
        $this->withToken($adminToken)
            ->getJson('/api/v1/admin/integrations')
            ->assertOk()
            ->assertJsonPath('tmdbConfigured', true);
        $auditIds = [
            $created->json('auditId'),
            $disabled->json('auditId'),
            $provider->json('auditId'),
            $source->json('auditId'),
            $settings->json('auditId'),
        ];
        $this->assertCount(5, array_unique($auditIds));
        foreach ($auditIds as $auditId) {
            $this->assertDatabaseHas('admin_audit_events', [
                'id' => $auditId,
                'user_id' => $admin->id,
            ]);
        }
        $this->assertSame(
            [
                'integrations.update',
                'iptv-provider.create',
                'media-source.create',
                'user.create',
                'user.disable',
            ],
            AdminAuditEvent::query()
                ->orderBy('action')
                ->pluck('action')
                ->all(),
        );
    }

    public function test_admin_audit_log_enforces_its_configured_row_cap(): void
    {
        config(['native-client.maximum_admin_audit_events' => 2]);
        $admin = User::factory()->create(['is_admin' => true]);
        $token = $this->login($admin, 'bounded-admin-audit');
        $auditIds = [];

        foreach (range(1, 3) as $index) {
            $requestId = 'admin-audit-'.$index;
            $response = $this->withHeaders([
                'Authorization' => 'Bearer '.$token,
                'X-Request-ID' => $requestId,
            ])->postJson('/api/v1/admin/users', [
                'name' => 'Audited User '.$index,
                'email' => 'audited-'.$index.'@example.test',
                'password' => 'VeryStrong!123',
                'passwordConfirmation' => 'VeryStrong!123',
            ])->assertCreated();
            $auditIds[] = (string) $response->json('auditId');
            $this->assertDatabaseHas('admin_audit_events', [
                'id' => $response->json('auditId'),
                'request_id' => $requestId,
            ]);
        }

        $this->assertDatabaseCount('admin_audit_events', 2);
        $this->assertDatabaseMissing('admin_audit_events', [
            'id' => $auditIds[0],
        ]);
        $this->assertDatabaseHas('admin_audit_events', [
            'id' => $auditIds[2],
        ]);
    }

    public function test_native_cleanup_is_bounded_and_dry_run_is_non_mutating(): void
    {
        config([
            'native-client.playback_grant_retention_days' => 1,
            'native-client.session_retention_days' => 7,
            'native-client.prune_batch_size' => 100,
        ]);
        $user = User::factory()->create();
        $activeToken = $this->login($user, 'active-cleanup');
        $activeSession = NativeClientSession::query()->sole();
        $stale = NativeClientSession::query()->create([
            'user_id' => $user->id,
            'installation_id_hash' => hash('sha256', 'stale-install'),
            'access_token_hash' => hash('sha256', 'stale-access'),
            'refresh_token_hash' => hash('sha256', 'stale-refresh'),
            'device_name' => 'Stale TV',
            'platform' => 'tvOS',
            'app_version' => '1.0',
            'access_expires_at' => now()->subDays(10),
            'refresh_expires_at' => now()->subDays(8),
            'revoked_at' => now()->subDays(8),
        ]);
        NativePlaybackGrant::query()->create([
            'native_client_session_id' => $stale->id,
            'user_id' => $user->id,
            'token_hash' => hash('sha256', 'stale-grant'),
            'resource_type' => 'media',
            'resource_id' => 'stale-media',
            'delivery_mode' => 'direct',
            'expires_at' => now()->subDays(8),
            'revoked_at' => now()->subDays(8),
        ]);
        $staleAudit = AdminAuditEvent::query()->create([
            'user_id' => $user->id,
            'action' => 'stale.test',
        ]);
        $staleAudit->forceFill([
            'created_at' => now()->subDays(366),
        ])->save();

        $this->artisan('native-client:prune', ['--dry-run' => true])
            ->assertSuccessful();
        $this->assertDatabaseHas('native_client_sessions', [
            'id' => $stale->id,
        ]);
        $this->assertDatabaseHas('admin_audit_events', [
            'id' => $staleAudit->id,
        ]);
        $this->artisan('native-client:prune')->assertSuccessful();
        $this->assertDatabaseMissing('native_client_sessions', [
            'id' => $stale->id,
        ]);
        $this->assertDatabaseHas('native_client_sessions', [
            'id' => $activeSession->id,
        ]);
        $this->assertDatabaseMissing('admin_audit_events', [
            'id' => $staleAudit->id,
        ]);
        $this->withToken($activeToken)->getJson('/api/v1/me')->assertOk();

        $event = collect(app(Schedule::class)->events())
            ->first(fn ($event) => str_contains(
                (string) $event->command,
                'native-client:prune',
            ));
        $this->assertNotNull($event);
        $this->assertSame('0 * * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping);
    }

    /**
     * @return array<string, string>
     */
    private function device(string $id = 'test-installation'): array
    {
        return [
            'installationId' => $id.'-0000000000000000',
            'deviceName' => 'Living Room',
            'platform' => 'tvOS',
            'appVersion' => '1.0',
            'osVersion' => '26.5',
        ];
    }

    private function login(User $user, string $device = 'test'): string
    {
        $user->forceFill(['password' => 'VeryStrong!123'])->save();

        return (string) $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'VeryStrong!123',
            'device' => $this->device($device),
        ])->assertOk()->json('accessToken');
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @param  array<string, mixed>  $overrides
     */
    private function mediaItem(
        User $user,
        string $title,
        array $metadata,
        array $overrides = [],
    ): MediaItem {
        $metadata['technical'] = array_replace([
            'width' => 1920,
            'height' => 1080,
            'frame_rate' => 24,
            'bit_rate' => 8_000_000,
            'video_profile' => 'Main',
            'video_level' => 4.1,
            'bit_depth' => 8,
            'dynamic_range' => 'sdr',
            'audio_channels' => 2,
            'audio_tracks' => [
                [
                    'codec' => $overrides['audio_codec'] ?? 'aac',
                    'language' => 'en',
                    'title' => 'English',
                ],
            ],
            'subtitle_tracks' => [],
        ], is_array($metadata['technical'] ?? null)
            ? $metadata['technical']
            : []);

        return MediaItem::query()->create([
            'user_id' => $user->id,
            'title' => $title,
            'media_kind' => 'video',
            'source_type' => 'local',
            'source_locator' => $this->temporaryPath.'/'.Str::slug($title),
            'container' => 'mp4',
            'video_codec' => 'h264',
            'audio_codec' => 'aac',
            'duration_ms' => 60_000,
            'metadata' => $metadata,
            ...$overrides,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function resolution(MediaItem $item): array
    {
        return [
            'mediaId' => (string) $item->id,
            'intent' => 'play',
            'positionMs' => 0,
            'device' => [
                'platform' => 'tvOS',
                'osVersion' => '26.5',
                'modelFamily' => 'AppleTV4K',
                'maximumWidth' => 3840,
                'maximumHeight' => 2160,
                'maximumFrameRate' => 60,
                'dynamicRanges' => [
                    'sdr',
                    'hdr10',
                    'hlg',
                    'dolbyVision',
                ],
                'videoCodecs' => ['h264', 'hevc'],
                'audioCodecs' => ['aac', 'ac3', 'eac3', 'alac', 'flac'],
                'subtitleFormats' => ['webvtt', 'imsc1'],
            ],
            'preferences' => [
                'preferOriginalQuality' => true,
                'allowTranscode' => true,
            ],
        ];
    }
}
