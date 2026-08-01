<?php

namespace Tests\Feature\Iptv;

use App\Jobs\Iptv\SyncXtreamSeries;
use App\Jobs\Media\EnrichMediaItem;
use App\Models\MediaItem;
use App\Models\MediaSource;
use App\Models\User;
use App\Services\Iptv\Exceptions\SanitizedIptvException;
use App\Services\Iptv\ProviderCatalogSynchronizer;
use App\Services\Iptv\XtreamClient;
use App\Services\Iptv\XtreamVodArtworkSynchronizer;
use App\Services\Iptv\XtreamVodCatalogSynchronizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\InteractsWithIptv;
use Tests\TestCase;

class IptvVodCatalogTest extends TestCase
{
    use InteractsWithIptv;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->allowPublicIptvDns();
        Http::preventStrayRequests();
    }

    public function test_xtream_movies_and_series_join_the_shared_media_catalog(): void
    {
        Queue::fake([EnrichMediaItem::class, SyncXtreamSeries::class]);
        $admin = User::factory()->create([
            'is_admin' => true,
            'is_active' => true,
        ]);
        $provider = $this->makeProvider(['name' => 'Nera IPTV']);
        $this->fakeCatalog();

        $this->assertSame(
            ['groups' => 0, 'channels' => 0, 'movies' => 1, 'series' => 1],
            app(ProviderCatalogSynchronizer::class)->sync($provider),
        );

        $source = MediaSource::query()->sole();
        $this->assertSame(MediaSource::TYPE_IPTV, $source->type);
        $this->assertSame($provider->id, $source->iptv_provider_id);
        $this->assertSame(
            ['range' => true, 'seekable' => true, 'read_only' => true],
            $source->capabilities,
        );
        $this->assertDatabaseCount('media_items', 2);
        $movie = MediaItem::query()
            ->where('metadata->kind', 'movie')
            ->sole();
        $show = MediaItem::query()
            ->where('metadata->kind', 'series')
            ->sole();
        $this->assertSame('Example Movie', $movie->title);
        $this->assertSame('Drama', $movie->metadata['category']);
        $this->assertSame(
            'https://image.tmdb.org/t/p/w500/movie.jpg',
            $movie->metadata['poster_url'],
        );
        $this->assertSame('Example Show', $show->metadata['series_title']);
        $this->assertSame(
            'https://image.tmdb.org/t/p/w500/show.jpg',
            $show->metadata['poster_url'],
        );
        $this->assertSame(
            'https://image.tmdb.org/t/p/w1280/show-backdrop.jpg',
            $show->metadata['backdrop_url'],
        );
        $this->assertStringNotContainsString(
            'test-user-secret',
            (string) DB::table('media_items')
                ->where('id', $movie->id)
                ->value('source_locator'),
        );
        Queue::assertPushed(
            SyncXtreamSeries::class,
            fn (SyncXtreamSeries $job): bool => $job->seriesId === '901',
        );
        Queue::assertPushed(EnrichMediaItem::class, 2);
        $this->assertSame('scanning', $source->refresh()->scan_status);
        $this->assertSame(1, $source->scan_discovered);
        $this->assertSame(0, $source->scan_processed);
        $this->assertSame('syncing', $provider->refresh()->sync_status);

        $this->actingAs($admin)
            ->get(route('media.index', [
                'kind' => 'video',
                'library' => 'movies',
                'source' => $source->id,
            ]))
            ->assertOk()
            ->assertSee('Example Movie')
            ->assertSee(route('media.artwork', [$movie, 'poster']), false)
            ->assertSee('Nera IPTV')
            ->assertDontSee('Example Show');

        $this->actingAs($admin)
            ->get(route('media.index', [
                'kind' => 'video',
                'library' => 'tv',
                'source' => $source->id,
            ]))
            ->assertOk()
            ->assertSee('Example Show')
            ->assertDontSee('Self-hosted')
            ->assertSee('Movies')
            ->assertSee('Series')
            ->assertSee('Live TV');
    }

    /**
     * @param  array<int, array<string, mixed>>  $movies
     * @param  array<int, array<string, mixed>>  $series
     */
    #[DataProvider('emptyVodReplacementResponses')]
    public function test_empty_vod_response_preserves_the_established_generation(
        array $movies,
        array $series,
    ): void {
        Queue::fake([EnrichMediaItem::class, SyncXtreamSeries::class]);
        User::factory()->create([
            'is_admin' => true,
            'is_active' => true,
        ]);
        $provider = $this->makeProvider(['name' => 'Nera IPTV']);
        $this->fakeCatalog();
        app(ProviderCatalogSynchronizer::class)->sync($provider);

        $source = MediaSource::query()->sole();
        $scanToken = $source->active_scan_token;
        $scanStatus = $source->scan_status;
        $itemIds = MediaItem::query()
            ->whereBelongsTo($source, 'source')
            ->whereIn('metadata->xtream_type', ['movie', 'series'])
            ->orderBy('id')
            ->pluck('id')
            ->all();
        $vodCategories = [[
            'category_id' => '10',
            'category_name' => 'Drama',
        ]];
        $seriesCategories = [[
            'category_id' => '20',
            'category_name' => 'TV Drama',
        ]];

        try {
            app(XtreamVodCatalogSynchronizer::class)->sync(
                $provider->fresh(),
                $vodCategories,
                $movies,
                $seriesCategories,
                $series,
            );
            $this->fail(
                'An empty response must not replace established VOD.',
            );
        } catch (SanitizedIptvException $exception) {
            $this->assertSame(
                'provider_vod_catalog_empty',
                $exception->errorCode,
            );
        }

        $source->refresh();
        $this->assertSame($scanToken, $source->active_scan_token);
        $this->assertSame($scanStatus, $source->scan_status);
        $this->assertSame(
            $itemIds,
            MediaItem::query()
                ->whereBelongsTo($source, 'source')
                ->whereIn('metadata->xtream_type', ['movie', 'series'])
                ->whereNull('missing_at')
                ->orderBy('id')
                ->pluck('id')
                ->all(),
        );
    }

    /**
     * @return iterable<string, array{
     *     array<int, array<string, mixed>>,
     *     array<int, array<string, mixed>>
     * }>
     */
    public static function emptyVodReplacementResponses(): iterable
    {
        yield 'movies endpoint is empty' => [
            [],
            [[
                'series_id' => '999',
                'name' => 'Replacement Show',
            ]],
        ];

        yield 'series endpoint is empty' => [
            [[
                'stream_id' => '999',
                'name' => 'Replacement Movie',
                'container_extension' => 'mp4',
            ]],
            [],
        ];

        yield 'entire VOD response is empty' => [[], []];
    }

    /**
     * @param  array<int, array<string, mixed>>  $vodCategories
     * @param  array<int, array<string, mixed>>  $seriesCategories
     */
    #[DataProvider('emptyVodCategoryReplacementResponses')]
    public function test_empty_vod_category_response_preserves_categorized_assets(
        array $vodCategories,
        array $seriesCategories,
        string $expectedErrorCode,
    ): void {
        Queue::fake([EnrichMediaItem::class, SyncXtreamSeries::class]);
        User::factory()->create([
            'is_admin' => true,
            'is_active' => true,
        ]);
        $provider = $this->makeProvider(['name' => 'Nera IPTV']);
        $this->fakeCatalog();
        app(ProviderCatalogSynchronizer::class)->sync($provider);

        $source = MediaSource::query()->sole();
        $scanToken = $source->active_scan_token;
        $scanStatus = $source->scan_status;
        $items = MediaItem::query()
            ->whereBelongsTo($source, 'source')
            ->whereIn('metadata->xtream_type', ['movie', 'series'])
            ->orderBy('id')
            ->get()
            ->mapWithKeys(fn (MediaItem $item): array => [
                $item->id => [
                    'title' => $item->title,
                    'category' => $item->metadata['category'],
                ],
            ])
            ->all();
        $movies = [[
            'stream_id' => '999',
            'name' => 'Replacement Movie',
            'category_id' => '10',
            'container_extension' => 'mp4',
        ]];
        $series = [[
            'series_id' => '998',
            'name' => 'Replacement Show',
            'category_id' => '20',
        ]];

        try {
            app(XtreamVodCatalogSynchronizer::class)->sync(
                $provider->fresh(),
                $vodCategories,
                $movies,
                $seriesCategories,
                $series,
            );
            $this->fail(
                'Empty category endpoints must not replace categorized VOD.',
            );
        } catch (SanitizedIptvException $exception) {
            $this->assertSame(
                $expectedErrorCode,
                $exception->errorCode,
            );
            $this->assertStringNotContainsString(
                'test-user-secret',
                $exception->getMessage(),
            );
            $this->assertStringNotContainsString(
                'test-password-secret',
                $exception->getMessage(),
            );
        }

        $source->refresh();
        $this->assertSame($scanToken, $source->active_scan_token);
        $this->assertSame($scanStatus, $source->scan_status);
        $this->assertSame(
            $items,
            MediaItem::query()
                ->whereBelongsTo($source, 'source')
                ->whereIn('metadata->xtream_type', ['movie', 'series'])
                ->whereNull('missing_at')
                ->orderBy('id')
                ->get()
                ->mapWithKeys(fn (MediaItem $item): array => [
                    $item->id => [
                        'title' => $item->title,
                        'category' => $item->metadata['category'],
                    ],
                ])
                ->all(),
        );
    }

    /**
     * @return iterable<string, array{
     *     array<int, array<string, mixed>>,
     *     array<int, array<string, mixed>>,
     *     string
     * }>
     */
    public static function emptyVodCategoryReplacementResponses(): iterable
    {
        yield 'movie categories endpoint is empty' => [
            [],
            [[
                'category_id' => '20',
                'category_name' => 'TV Drama',
            ]],
            'provider_vod_categories_empty',
        ];

        yield 'series categories endpoint is empty' => [
            [[
                'category_id' => '10',
                'category_name' => 'Drama',
            ]],
            [],
            'provider_series_categories_empty',
        ];
    }

    public function test_series_details_are_flattened_into_playable_episodes(): void
    {
        Queue::fake([EnrichMediaItem::class, SyncXtreamSeries::class]);
        User::factory()->create(['is_admin' => true, 'is_active' => true]);
        $provider = $this->makeProvider(['name' => 'Nera IPTV']);
        $this->fakeCatalog();
        app(ProviderCatalogSynchronizer::class)->sync($provider);
        $source = MediaSource::query()->sole();

        $job = Queue::pushed(SyncXtreamSeries::class)->first();
        $this->assertInstanceOf(SyncXtreamSeries::class, $job);
        app()->call([$job, 'handle']);

        $episode = MediaItem::query()
            ->where('metadata->kind', 'episode')
            ->sole();
        $this->assertSame('Pilot', $episode->title);
        $this->assertSame(1, $episode->metadata['season_number']);
        $this->assertSame(1, $episode->metadata['episode_number']);
        $this->assertSame(
            'https://image.tmdb.org/t/p/w300/episode-still.jpg',
            $episode->metadata['poster_url'],
        );
        $this->assertSame(
            'https://image.tmdb.org/t/p/w300/episode-still.jpg',
            $episode->metadata['backdrop_url'],
        );
        $this->assertTrue($episode->requires_transcode);
        $this->assertSame('episode', json_decode(
            $episode->source_locator,
            true,
            8,
            JSON_THROW_ON_ERROR,
        )['type']);
        Queue::assertPushed(
            EnrichMediaItem::class,
            fn (EnrichMediaItem $job): bool => $job->mediaItemId === $episode->id,
        );
        $this->assertSame('ready', $source->refresh()->scan_status);
        $this->assertSame(1, $source->scan_processed);
        $this->assertSame('ready', $provider->refresh()->sync_status);
    }

    public function test_empty_series_episode_response_preserves_established_episodes_for_retry(): void
    {
        Queue::fake([EnrichMediaItem::class, SyncXtreamSeries::class]);
        User::factory()->create(['is_admin' => true, 'is_active' => true]);
        $provider = $this->makeProvider(['name' => 'Nera IPTV']);
        $this->fakeCatalog();
        app(ProviderCatalogSynchronizer::class)->sync($provider);
        $source = MediaSource::query()->sole();

        $initialJob = Queue::pushed(SyncXtreamSeries::class)->first();
        $this->assertInstanceOf(SyncXtreamSeries::class, $initialJob);
        app()->call([$initialJob, 'handle']);

        $episode = MediaItem::query()
            ->where('metadata->kind', 'episode')
            ->sole();
        $originalScanToken = $episode->scan_token;
        $replacementToken = str_repeat('9', 26);
        $source->refresh();
        $source->forceFill([
            'scan_status' => 'scanning',
            'active_scan_token' => $replacementToken,
            'scan_discovery_complete' => true,
            'scan_discovered' => 1,
            'scan_processed' => 0,
            'scan_failed' => 0,
        ])->save();
        $this->assertSame(
            $replacementToken,
            $source->fresh()->active_scan_token,
        );
        $this->assertTrue(
            MediaItem::query()
                ->whereBelongsTo($source, 'source')
                ->whereNull('missing_at')
                ->where('metadata->kind', 'episode')
                ->where('metadata->xtream_series_id', '901')
                ->exists(),
        );

        $this->allowPublicIptvDns();
        Http::preventStrayRequests();
        Http::fake(function (Request $request) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
            $this->assertSame('get_series_info', $query['action']);
            $this->assertSame('901', $query['series_id']);

            return Http::response([
                'info' => ['name' => 'Example Show'],
                'episodes' => [],
            ]);
        });

        $replacementJob = new SyncXtreamSeries(
            $provider->id,
            $source->id,
            '901',
            'Example Show',
            $replacementToken,
        );

        try {
            app()->call([$replacementJob, 'handle']);
            $this->fail(
                'Empty series details must be retried before replacing episodes.',
            );
        } catch (SanitizedIptvException $exception) {
            $this->assertSame(
                'provider_series_episodes_empty',
                $exception->errorCode,
            );
            $this->assertStringNotContainsString(
                'test-user-secret',
                $exception->getMessage(),
            );
            $this->assertStringNotContainsString(
                'test-password-secret',
                $exception->getMessage(),
            );
        }

        $this->assertSame(3, $replacementJob->tries);
        $this->assertSame(
            $originalScanToken,
            $episode->refresh()->scan_token,
        );
        $this->assertNull($episode->missing_at);
        $this->assertSame('Pilot', $episode->title);
        $this->assertSame(
            $replacementToken,
            $source->refresh()->active_scan_token,
        );
        $this->assertSame('scanning', $source->scan_status);
        $this->assertSame(0, $source->scan_processed);
    }

    public function test_episode_pages_fallback_to_parent_series_artwork(): void
    {
        Queue::fake([EnrichMediaItem::class, SyncXtreamSeries::class]);
        $admin = User::factory()->create([
            'is_admin' => true,
            'is_active' => true,
        ]);
        $provider = $this->makeProvider(['name' => 'Nera IPTV']);
        $this->fakeCatalog(withEpisodeArtwork: false);
        app(ProviderCatalogSynchronizer::class)->sync($provider);
        $source = MediaSource::query()->sole();
        $job = Queue::pushed(SyncXtreamSeries::class)->first();
        app()->call([$job, 'handle']);

        $show = MediaItem::query()
            ->where('metadata->kind', 'series')
            ->sole();
        $episode = MediaItem::query()
            ->where('metadata->kind', 'episode')
            ->sole();
        $seriesArtwork = route('media.artwork', [$show, 'poster']);
        $episodeArtwork = route('media.artwork', [$episode, 'poster']);

        $this->actingAs($admin)
            ->get(route('media.index', [
                'kind' => 'video',
                'library' => 'tv',
                'series' => 'Example Show',
                'source' => $source->id,
            ]))
            ->assertOk()
            ->assertSee($seriesArtwork, escape: false)
            ->assertDontSee($episodeArtwork, escape: false);

        $this->actingAs($admin)
            ->get(route('media.show', $episode))
            ->assertOk()
            ->assertSee($seriesArtwork, escape: false);
    }

    public function test_series_details_preserve_season_zero_specials(): void
    {
        Queue::fake([EnrichMediaItem::class, SyncXtreamSeries::class]);
        User::factory()->create(['is_admin' => true, 'is_active' => true]);
        $provider = $this->makeProvider(['name' => 'Nera IPTV']);
        $this->fakeCatalog('0');
        app(ProviderCatalogSynchronizer::class)->sync($provider);

        $job = Queue::pushed(SyncXtreamSeries::class)->first();
        $this->assertInstanceOf(SyncXtreamSeries::class, $job);
        app()->call([$job, 'handle']);

        $episode = MediaItem::query()
            ->where('metadata->kind', 'episode')
            ->sole();
        $this->assertSame(0, $episode->metadata['season_number']);
        $this->assertSame(1, $episode->metadata['episode_number']);
        $this->assertStringContainsString(
            '/Season 00/',
            (string) $episode->relative_path,
        );
    }

    public function test_provider_series_metadata_without_episodes_is_an_empty_series(): void
    {
        $provider = $this->makeProvider(['name' => 'Nera IPTV']);
        Http::fake(function (Request $request) {
            parse_str(
                (string) parse_url($request->url(), PHP_URL_QUERY),
                $query,
            );
            $this->assertSame('get_series_info', $query['action']);
            $this->assertSame('49926', $query['series_id']);

            return Http::response([
                'info' => ['name' => 'Empty Nera series'],
                'seasons' => [],
            ]);
        });

        $payload = app(XtreamClient::class)->seriesInfo(
            $provider,
            '49926',
        );

        $this->assertSame([], $payload['episodes']);
    }

    public function test_artwork_refresh_backfills_existing_catalog_records(): void
    {
        Queue::fake([EnrichMediaItem::class, SyncXtreamSeries::class]);
        User::factory()->create(['is_admin' => true, 'is_active' => true]);
        $provider = $this->makeProvider(['name' => 'Nera IPTV']);
        $this->fakeCatalog();
        app(ProviderCatalogSynchronizer::class)->sync($provider);

        MediaItem::query()->get()->each(function (MediaItem $item): void {
            $metadata = $item->metadata;
            unset(
                $metadata['poster_url'],
                $metadata['backdrop_url'],
            );
            $item->update(['metadata' => $metadata]);
        });

        $this->assertSame(
            ['movies' => 1, 'series' => 1, 'updated' => 2],
            app(XtreamVodArtworkSynchronizer::class)->sync($provider),
        );

        $movie = MediaItem::query()
            ->where('metadata->kind', 'movie')
            ->sole();
        $show = MediaItem::query()
            ->where('metadata->kind', 'series')
            ->sole();
        $this->assertSame(
            'https://image.tmdb.org/t/p/w500/movie.jpg',
            $movie->metadata['poster_url'],
        );
        $this->assertSame(
            'https://image.tmdb.org/t/p/w500/show.jpg',
            $show->metadata['poster_url'],
        );
        $this->assertSame(
            'https://image.tmdb.org/t/p/w1280/show-backdrop.jpg',
            $show->metadata['backdrop_url'],
        );
    }

    public function test_iptv_vod_uses_the_authenticated_media_proxy_with_ranges(): void
    {
        $viewer = User::factory()->create(['is_active' => true]);
        $provider = $this->makeProvider(['name' => 'Nera IPTV']);
        $source = MediaSource::query()->create([
            'iptv_provider_id' => $provider->id,
            'name' => 'Nera IPTV · IPTV #'.$provider->id,
            'type' => MediaSource::TYPE_IPTV,
            'configuration' => ['managed' => true],
            'capabilities' => [
                'range' => true,
                'seekable' => true,
                'read_only' => true,
            ],
            'enabled' => true,
            'scan_status' => 'ready',
        ]);
        $item = MediaItem::query()->create([
            'user_id' => $viewer->id,
            'media_source_id' => $source->id,
            'stable_id' => hash('sha256', 'xtream:movie:501'),
            'title' => 'Example Movie',
            'media_kind' => 'video',
            'source_type' => MediaSource::TYPE_IPTV,
            'source_locator' => json_encode([
                'type' => 'movie',
                'id' => '501',
                'extension' => 'mp4',
            ], JSON_THROW_ON_ERROR),
            'mime_type' => 'video/mp4',
            'container' => 'mp4',
            'requires_transcode' => false,
            'size_bytes' => 10,
            'metadata' => ['kind' => 'movie'],
        ]);

        Http::fake(function (Request $request) {
            $this->assertSame(
                '/movie/test-user-secret/test-password-secret/501.mp4',
                parse_url($request->url(), PHP_URL_PATH),
            );
            $this->assertSame(['bytes=2-5'], $request->header('Range'));
            $this->assertSame(
                ['iptv.example.test'],
                $request->header('Host'),
            );

            return Http::response('2345', 206, [
                'Content-Length' => '4',
                'Content-Range' => 'bytes 2-5/10',
                'Content-Type' => 'video/mp4',
            ]);
        });

        $this->actingAs($viewer)
            ->withHeader('Range', 'bytes=2-5')
            ->get(route('media.direct', $item))
            ->assertStatus(206)
            ->assertHeader('Content-Range', 'bytes 2-5/10')
            ->assertHeader('Accept-Ranges', 'bytes')
            ->assertStreamedContent('2345');
    }

    private function fakeCatalog(
        string $season = '1',
        bool $withEpisodeArtwork = true,
    ): void {
        Http::fake(function (Request $request) use (
            $season,
            $withEpisodeArtwork,
        ) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            return Http::response(match ($query['action'] ?? 'authenticate') {
                'authenticate' => ['user_info' => ['auth' => 1]],
                'get_live_categories', 'get_live_streams' => [],
                'get_vod_categories' => [[
                    'category_id' => '10',
                    'category_name' => 'Drama',
                ]],
                'get_vod_streams' => [[
                    'stream_id' => '501',
                    'name' => 'Example Movie',
                    'category_id' => '10',
                    'container_extension' => 'mp4',
                    'plot' => 'A sample film.',
                    'year' => '2024',
                    'stream_icon' => 'http://image.tmdb.org/t/p/w500/movie.jpg',
                ]],
                'get_series_categories' => [[
                    'category_id' => '20',
                    'category_name' => 'TV Drama',
                ]],
                'get_series' => [[
                    'series_id' => '901',
                    'name' => 'Example Show',
                    'category_id' => '20',
                    'plot' => 'A sample series.',
                    'cover' => 'https://www.themoviedb.org/t/p/w500/show.jpg',
                    'backdrop_path' => [
                        'https://image.tmdb.org/t/p/w1280/show-backdrop.jpg',
                    ],
                ]],
                'get_series_info' => [
                    'info' => ['name' => 'Example Show'],
                    'episodes' => [
                        $season => [[
                            'id' => '902',
                            'episode_num' => 1,
                            'title' => 'Pilot',
                            'container_extension' => 'mkv',
                            'info' => [
                                'plot' => 'The first episode.',
                                'rating' => '8.1',
                                'movie_image' => $withEpisodeArtwork
                                    ? 'https://image.tmdb.org/t/p/w300/episode-still.jpg'
                                    : null,
                            ],
                        ]],
                    ],
                ],
            });
        });
    }
}
