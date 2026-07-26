<?php

namespace Tests\Feature\Iptv;

use App\Jobs\Iptv\SyncIptvGuide;
use App\Models\Iptv\Channel;
use App\Models\Iptv\ChannelGroup;
use App\Services\Iptv\Exceptions\SanitizedIptvException;
use App\Services\Iptv\GuideImportResult;
use App\Services\Iptv\ProviderCatalogSynchronizer;
use App\Services\Iptv\ShortEpgGuideImporter;
use App\Services\Iptv\XtreamClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Mockery\MockInterface;
use Tests\Concerns\InteractsWithIptv;
use Tests\TestCase;

class IptvCatalogAndGuideTest extends TestCase
{
    use InteractsWithIptv;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->allowPublicIptvDns();
        Http::preventStrayRequests();
    }

    public function test_catalog_sync_is_batched_idempotent_and_marks_missing_rows_inactive(): void
    {
        $provider = $this->makeProvider();
        $responses = [
            'authenticate' => [
                'user_info' => ['auth' => 1, 'status' => 'Active'],
            ],
            'get_live_categories' => [
                ['category_id' => '7', 'category_name' => 'International News'],
                ['category_id' => '7', 'category_name' => 'International News'],
            ],
            'get_live_streams' => [
                [
                    'stream_id' => 1001,
                    'name' => 'World News HD',
                    'category_id' => '7',
                    'epg_channel_id' => 'world.news',
                    'num' => 12,
                    'stream_icon' => 'https://images.example.test/world.png',
                    'tv_archive' => 0,
                ],
                [
                    'stream_id' => 1001,
                    'name' => 'World News HD',
                    'category_id' => '7',
                    'epg_channel_id' => 'world.news',
                    'num' => 12,
                    'stream_icon' => 'https://images.example.test/world.png',
                    'tv_archive' => 0,
                ],
            ],
        ];

        Http::fake(function (Request $request) use (&$responses) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            return Http::response(
                $responses[$query['action'] ?? 'authenticate'],
                200,
            );
        });

        $sync = app(ProviderCatalogSynchronizer::class);
        $this->assertSame(
            ['groups' => 1, 'channels' => 1],
            $sync->sync($provider),
        );
        $sync->sync($provider);

        $this->assertDatabaseCount('channel_groups', 1);
        $this->assertDatabaseCount('channels', 1);
        $this->assertTrue(Channel::query()->sole()->is_active);
        $this->assertSame(
            ['archive' => false, 'added' => null],
            Channel::query()->sole()->metadata,
        );
        $this->assertSame(
            'https://images.example.test/world.png',
            Channel::query()->sole()->stream_icon,
        );
        $rawChannel = DB::table('channels')->first();
        $this->assertStringNotContainsString('images.example.test', (string) $rawChannel->stream_icon);
        $this->assertStringNotContainsString('archive', (string) $rawChannel->metadata);

        $responses = [
            'authenticate' => [
                'user_info' => ['auth' => 1, 'status' => 'Active'],
            ],
            'get_live_categories' => [],
            'get_live_streams' => [],
        ];
        $this->assertSame(
            ['groups' => 0, 'channels' => 0],
            $sync->sync($provider->fresh()),
        );
        $this->assertFalse(Channel::query()->sole()->is_active);
        $this->assertFalse(ChannelGroup::query()->sole()->is_active);
    }

    public function test_short_epg_import_is_bounded_and_idempotent(): void
    {
        $provider = $this->makeProvider();
        $channel = $this->makeChannel($provider);
        $start = now()->startOfMinute();
        $shortRequests = 0;

        Http::fake(function (Request $request) use ($start, &$shortRequests) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            if (! isset($query['action'])) {
                return Http::response([
                    'user_info' => ['auth' => '1', 'status' => 'Active'],
                ]);
            }

            $shortRequests++;
            $this->assertSame('get_short_epg', $query['action']);
            $this->assertSame('4', (string) $query['limit']);

            return Http::response([
                'epg_listings' => [
                    [
                        'id' => 'now',
                        'title' => base64_encode('News Now'),
                        'description' => base64_encode('Live headlines'),
                        'start_timestamp' => $start->timestamp,
                        'stop_timestamp' => $start->copy()->addHour()->timestamp,
                    ],
                    [
                        'id' => 'next',
                        'title' => base64_encode('News Next'),
                        'description' => base64_encode('Coming up'),
                        'start_timestamp' => $start->copy()->addHour()->timestamp,
                        'stop_timestamp' => $start->copy()->addHours(2)->timestamp,
                    ],
                ],
            ]);
        });

        $importer = app(ShortEpgGuideImporter::class);
        $this->assertSame(2, $importer->import($provider, 1)->programsImported);
        $this->assertSame(2, $importer->import($provider->fresh(), 1)->programsImported);
        $this->assertSame(2, $shortRequests);
        $this->assertDatabaseCount('epg_programs', 2);
        $this->assertSame(
            ['News Now', 'News Next'],
            $channel->programs()->orderBy('starts_at')->pluck('title')->all(),
        );
    }

    public function test_short_epg_import_enforces_the_provider_guide_row_quota(): void
    {
        config()->set('iptv.provider_guide_max_rows', 1);
        $provider = $this->makeProvider();
        $channel = $this->makeChannel($provider);
        $start = now()->startOfMinute();

        Http::fake(function (Request $request) use ($start) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            if (! isset($query['action'])) {
                return Http::response([
                    'user_info' => ['auth' => '1', 'status' => 'Active'],
                ]);
            }

            return Http::response([
                'epg_listings' => [
                    [
                        'id' => 'first',
                        'title' => base64_encode('First programme'),
                        'start_timestamp' => $start->timestamp,
                        'stop_timestamp' => $start->copy()->addHour()->timestamp,
                    ],
                    [
                        'id' => 'second',
                        'title' => base64_encode('Second programme'),
                        'start_timestamp' => $start->copy()->addHour()->timestamp,
                        'stop_timestamp' => $start->copy()->addHours(2)->timestamp,
                    ],
                ],
            ]);
        });

        $this->assertSame(
            2,
            app(ShortEpgGuideImporter::class)
                ->import($provider, 1)
                ->programsImported,
        );
        $this->assertSame(
            ['First programme'],
            $channel->programs()->pluck('title')->all(),
        );
    }

    public function test_http_200_authentication_failure_never_marks_a_provider_ready(): void
    {
        $provider = $this->makeProvider();
        Http::fake(fn () => Http::response([
            'user_info' => ['auth' => 0, 'status' => 'Disabled'],
        ], 200));

        try {
            app(ProviderCatalogSynchronizer::class)->sync($provider);
            $this->fail('Invalid credentials must stop catalog sync.');
        } catch (SanitizedIptvException $exception) {
            $this->assertSame('provider_authentication_failed', $exception->errorCode);
            $this->assertStringNotContainsString('test-user-secret', $exception->getMessage());
            $this->assertStringNotContainsString('test-password-secret', $exception->getMessage());
        }

        $this->assertSame('syncing', $provider->fresh()->sync_status);
        $this->assertDatabaseCount('channels', 0);
    }

    public function test_manual_guide_sync_is_hard_capped_without_chaining_the_full_catalog(): void
    {
        $provider = $this->makeProvider();
        $group = ChannelGroup::query()->create([
            'iptv_provider_id' => $provider->id,
            'external_id' => 'batch',
            'name' => 'Batch',
            'is_active' => true,
        ]);

        foreach (range(1, 25) as $index) {
            Channel::query()->create([
                'iptv_provider_id' => $provider->id,
                'channel_group_id' => $group->id,
                'external_id' => (string) (3000 + $index),
                'name' => "Batch channel {$index}",
                'stream_extension' => 'm3u8',
                'metadata' => [],
                'is_active' => true,
            ]);
        }

        $shortRequests = 0;
        Http::fake(function (Request $request) use (&$shortRequests) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            if (! isset($query['action'])) {
                return Http::response(['user_info' => ['auth' => 1]]);
            }

            $shortRequests++;

            return Http::response(['epg_listings' => []]);
        });

        $importer = app(ShortEpgGuideImporter::class);
        $first = $importer->import($provider, 250);
        $this->assertTrue($first->hasMore);
        $this->assertSame(20, $shortRequests);

        $second = $importer->import($provider, 250, $first->lastChannelId);
        $this->assertFalse($second->hasMore);
        $this->assertSame(25, $shortRequests);

        Queue::fake();
        $mockImporter = $this->mock(
            ShortEpgGuideImporter::class,
            fn (MockInterface $mock) => $mock
                ->shouldReceive('import')
                ->once()
                ->andReturn(new GuideImportResult(0, $first->lastChannelId, true)),
        );

        (new SyncIptvGuide($provider->id))->handle($mockImporter);

        Queue::assertNothingPushed();
        $this->assertNotNull($provider->fresh()->last_guide_synced_at);
    }

    public function test_catalog_response_size_and_row_limits_are_enforced_before_persistence(): void
    {
        $provider = $this->makeProvider();
        $mode = 'size';
        config()->set('iptv.api_max_response_bytes', PHP_INT_MAX);
        Http::fake(function (Request $request) use (&$mode) {
            if ($mode === 'size') {
                return Http::response('{}', 200, [
                    'Content-Length' => (string) ((8 * 1024 * 1024) + 1),
                ]);
            }

            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            return match ($query['action'] ?? 'authenticate') {
                'authenticate' => Http::response(['user_info' => ['auth' => 1]]),
                'get_live_categories' => Http::response([]),
                'get_live_streams' => Http::response([
                    ['stream_id' => 1],
                    ['stream_id' => 2],
                    ['stream_id' => 3],
                ]),
            };
        });

        try {
            app(ProviderCatalogSynchronizer::class)->sync($provider);
            $this->fail('Oversized authentication responses must be rejected.');
        } catch (SanitizedIptvException $exception) {
            $this->assertSame('provider_response_too_large', $exception->errorCode);
        }

        config()->set('iptv.channel_max_rows', 2);
        $mode = 'rows';

        try {
            app(ProviderCatalogSynchronizer::class)->sync($provider->fresh());
            $this->fail('Catalog row limits must be enforced.');
        } catch (SanitizedIptvException $exception) {
            $this->assertSame('provider_channel_limit', $exception->errorCode);
        }

        $this->assertDatabaseCount('channels', 0);
    }

    public function test_decoded_xtream_payload_is_bounded_during_response_writes(): void
    {
        config()->set('iptv.api_max_response_bytes', 1024 * 1024);
        $provider = $this->makeProvider();
        Http::fake(fn () => Http::response(
            json_encode([
                'user_info' => ['auth' => '1'],
                'padding' => str_repeat('x', (1024 * 1024) + 1),
            ], JSON_THROW_ON_ERROR),
            200,
            ['Content-Type' => 'application/json'],
        ));

        try {
            app(XtreamClient::class)->authenticate($provider);
            $this->fail('Decoded provider responses must be bounded while written.');
        } catch (SanitizedIptvException $exception) {
            $this->assertSame(
                'provider_response_too_large',
                $exception->errorCode,
            );
        }
    }
}
