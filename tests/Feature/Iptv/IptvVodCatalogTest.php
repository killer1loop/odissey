<?php

namespace Tests\Feature\Iptv;

use App\Jobs\Iptv\SyncXtreamSeries;
use App\Jobs\Media\EnrichMediaItem;
use App\Models\MediaItem;
use App\Models\MediaSource;
use App\Models\User;
use App\Services\Iptv\ProviderCatalogSynchronizer;
use App\Services\Iptv\XtreamClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
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
        $this->assertSame('Example Show', $show->metadata['series_title']);
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
            ->assertSee('TV Shows')
            ->assertSee('Live TV');
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

    private function fakeCatalog(): void
    {
        Http::fake(function (Request $request) {
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
                ]],
                'get_series_info' => [
                    'info' => ['name' => 'Example Show'],
                    'episodes' => [
                        '1' => [[
                            'id' => '902',
                            'episode_num' => 1,
                            'title' => 'Pilot',
                            'container_extension' => 'mkv',
                            'info' => [
                                'plot' => 'The first episode.',
                                'rating' => '8.1',
                            ],
                        ]],
                    ],
                ],
            });
        });
    }
}
