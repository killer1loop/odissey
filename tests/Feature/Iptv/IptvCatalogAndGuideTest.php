<?php

namespace Tests\Feature\Iptv;

use App\Jobs\Iptv\SyncIptvGuide;
use App\Jobs\Iptv\SyncIptvProvider;
use App\Jobs\Iptv\SyncXtreamSeries;
use App\Jobs\Media\EnrichMediaItem;
use App\Models\Iptv\Channel;
use App\Models\Iptv\ChannelGroup;
use App\Models\User;
use App\Services\Iptv\Exceptions\SanitizedIptvException;
use App\Services\Iptv\IptvImportMemoryBudget;
use App\Services\Iptv\ProviderCatalogSynchronizer;
use App\Services\Iptv\ShortEpgGuideImporter;
use App\Services\Iptv\XmltvGuideImporter;
use App\Services\Iptv\XtreamClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
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

    public function test_upgrade_recovery_queues_enabled_provider_catalogs(): void
    {
        Queue::fake();
        $provider = $this->makeProvider([
            'sync_status' => 'pending',
            'last_error_code' => 'provider_catalog_upgrade_required',
        ]);
        $this->makeProvider([
            'name' => 'Already current',
            'last_error_code' => null,
        ]);

        $this->artisan(
            'iptv:catalog:refresh',
            ['--recover-upgrade' => true],
        )->expectsOutput('Queued 1 IPTV catalog refresh(es).')
            ->assertSuccessful();

        Queue::assertPushed(
            SyncIptvProvider::class,
            fn (SyncIptvProvider $job): bool => (
                $job->providerId === $provider->id
            ),
        );
        $this->assertSame('queued', $provider->refresh()->sync_status);
        $this->assertNull($provider->last_error_code);
    }

    public function test_catalog_sync_is_batched_idempotent_and_rejects_an_empty_replacement_generation(): void
    {
        Queue::fake([EnrichMediaItem::class, SyncXtreamSeries::class]);
        User::factory()->create(['is_admin' => true, 'is_active' => true]);
        $provider = $this->makeProvider();
        $responses = [
            'authenticate' => [
                'user_info' => [
                    'auth' => 1,
                    'status' => 'Active',
                    'max_connections' => '4',
                ],
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
            'get_vod_categories' => [],
            'get_vod_streams' => [],
            'get_series_categories' => [],
            'get_series' => [],
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
            ['groups' => 1, 'channels' => 1, 'movies' => 0, 'series' => 0],
            $sync->sync($provider),
        );
        $sync->sync($provider);

        $this->assertDatabaseCount('channel_groups', 1);
        $this->assertDatabaseCount('channels', 1);
        $this->assertTrue(Channel::query()->sole()->is_active);
        $this->assertSame(4, $provider->fresh()->config['max_connections']);
        $this->assertSame(
            'provider',
            $provider->fresh()->config['max_connections_source'],
        );
        $this->assertSame(
            ['archive' => false, 'added' => null],
            Channel::query()->sole()->metadata,
        );
        $this->assertNull(Channel::query()->sole()->stream_icon);
        $this->assertNull(Channel::query()->sole()->logo_source);
        $rawChannel = DB::table('channels')->first();
        $this->assertStringNotContainsString('images.example.test', (string) $rawChannel->stream_icon);
        $this->assertStringNotContainsString('archive', (string) $rawChannel->metadata);

        $responses = [
            'authenticate' => [
                'user_info' => ['auth' => 1, 'status' => 'Active'],
            ],
            'get_live_categories' => [[
                'category_id' => '7',
                'category_name' => 'International News',
            ]],
            'get_live_streams' => [],
            'get_vod_categories' => [],
            'get_vod_streams' => [],
            'get_series_categories' => [],
            'get_series' => [],
        ];
        try {
            $sync->sync($provider->fresh());
            $this->fail(
                'An empty response must not replace an established catalog.',
            );
        } catch (SanitizedIptvException $exception) {
            $this->assertSame(
                'provider_catalog_empty',
                $exception->errorCode,
            );
        }
        $this->assertTrue(Channel::query()->sole()->is_active);
        $this->assertTrue(ChannelGroup::query()->sole()->is_active);
    }

    public function test_empty_live_categories_preserve_established_groups(): void
    {
        $provider = $this->makeProvider();
        $channel = $this->makeChannel($provider);
        $group = $channel->group;

        Http::fake(function (Request $request) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            return Http::response(match ($query['action'] ?? 'authenticate') {
                'authenticate' => [
                    'user_info' => ['auth' => 1, 'status' => 'Active'],
                ],
                'get_live_categories' => [],
                'get_live_streams' => [[
                    'stream_id' => '101',
                    'name' => 'Replacement News',
                    'category_id' => 'news',
                ]],
            });
        });

        try {
            app(ProviderCatalogSynchronizer::class)->sync($provider);
            $this->fail(
                'Empty live categories must not replace established groups.',
            );
        } catch (SanitizedIptvException $exception) {
            $this->assertSame(
                'provider_live_categories_empty',
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

        $this->assertTrue($group->refresh()->is_active);
        $this->assertSame('News', $group->name);
        $this->assertTrue($channel->refresh()->is_active);
        $this->assertSame('Example News', $channel->name);
        Http::assertSentCount(3);
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

    public function test_short_guide_batches_can_resume_after_the_hard_request_cap(): void
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
    }

    public function test_xtream_guide_sync_uses_bulk_xmltv_beyond_the_short_guide_cap(): void
    {
        $provider = $this->makeProvider();
        $group = ChannelGroup::query()->create([
            'iptv_provider_id' => $provider->id,
            'external_id' => 'bulk',
            'name' => 'Bulk',
            'is_active' => true,
        ]);
        $start = now()->utc()->addHour();
        $programmes = '';

        foreach (range(1, 25) as $index) {
            Channel::query()->create([
                'iptv_provider_id' => $provider->id,
                'channel_group_id' => $group->id,
                'external_id' => (string) (4000 + $index),
                'epg_channel_id' => "bulk.{$index}",
                'name' => "Bulk channel {$index}",
                'stream_extension' => 'm3u8',
                'metadata' => [],
                'is_active' => true,
            ]);
            $programmes .= sprintf(
                '<programme channel="bulk.%d" start="%s +0000" stop="%s +0000"><title>Programme %d</title></programme>',
                $index,
                $start->format('YmdHis'),
                $start->copy()->addHour()->format('YmdHis'),
                $index,
            );
        }
        Channel::query()->create([
            'iptv_provider_id' => $provider->id,
            'channel_group_id' => $group->id,
            'external_id' => 'duplicate-quality-variant',
            'epg_channel_id' => 'bulk.25',
            'name' => 'Bulk channel 25 UHD',
            'stream_extension' => 'm3u8',
            'metadata' => [],
            'is_active' => true,
        ]);

        Http::fake(function (Request $request) use ($programmes) {
            $this->assertStringEndsWith('/xmltv.php', (string) parse_url(
                $request->url(),
                PHP_URL_PATH,
            ));

            return Http::response(
                "<?xml version=\"1.0\"?><!DOCTYPE tv SYSTEM \"xmltv.dtd\"><tv>{$programmes}</tv>",
                200,
                ['Content-Type' => 'application/xml'],
            );
        });

        (new SyncIptvGuide($provider->id))
            ->handle(
                app(XmltvGuideImporter::class),
                app(IptvImportMemoryBudget::class),
            );

        $this->assertDatabaseCount('epg_programs', 26);
        $this->assertDatabaseHas('epg_programs', [
            'title' => 'Programme 25',
        ]);
        $this->assertSame(
            2,
            Channel::query()
                ->where('epg_channel_id', 'bulk.25')
                ->get()
                ->sum(fn (Channel $channel): int => $channel->programs()->count()),
        );
        $this->assertNotNull($provider->fresh()->last_guide_synced_at);
    }

    public function test_xtream_bulk_xmltv_hard_limit_cannot_be_configured_away(): void
    {
        config()->set('iptv.xtream_xmltv_max_bytes', PHP_INT_MAX);
        $provider = $this->makeProvider();

        Http::fake([
            'iptv.example.test/*' => Http::response(
                '<tv></tv>',
                200,
                ['Content-Length' => (string) ((128 * 1024 * 1024) + 1)],
            ),
        ]);

        try {
            app(XmltvGuideImporter::class)->importXtream($provider);
            $this->fail('The Xtream XMLTV hard limit must not be configurable away.');
        } catch (SanitizedIptvException $exception) {
            $this->assertSame('xmltv_invalid', $exception->errorCode);
        }

        $this->assertDatabaseCount('epg_programs', 0);
    }

    public function test_catalog_response_size_and_row_limits_are_enforced_before_persistence(): void
    {
        $provider = $this->makeProvider();
        $mode = 'size';
        config()->set('iptv.api_max_response_bytes', PHP_INT_MAX);
        Http::fake(function (Request $request) use (&$mode) {
            if ($mode === 'size') {
                return Http::response('{}', 200, [
                    'Content-Length' => (string) ((32 * 1024 * 1024) + 1),
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

    public function test_large_provider_catalogs_within_the_bounded_live_channel_limit_are_accepted(): void
    {
        $provider = $this->makeProvider();
        $channels = array_map(
            static fn (int $streamId): array => ['stream_id' => $streamId],
            range(1, 21313),
        );

        Http::fake(fn () => Http::response($channels));

        $this->assertCount(
            21313,
            app(XtreamClient::class)->liveStreams($provider),
        );
    }

    public function test_provider_import_memory_budget_is_hard_clamped(): void
    {
        config()->set('iptv.import_memory_limit_mb', 1);
        $this->assertSame(256, app(IptvImportMemoryBudget::class)->megabytes());

        config()->set('iptv.import_memory_limit_mb', PHP_INT_MAX);
        $this->assertSame(1024, app(IptvImportMemoryBudget::class)->megabytes());
    }

    public function test_guide_sync_applies_import_memory_budget(): void
    {
        $provider = $this->makeProvider([
            'config' => ['api' => 'm3u'],
        ]);
        $originalLimit = ini_get('memory_limit');
        config()->set('iptv.import_memory_limit_mb', 333);

        try {
            (new SyncIptvGuide($provider->id))
                ->handle(
                    app(XmltvGuideImporter::class),
                    app(IptvImportMemoryBudget::class),
                );

            $this->assertSame('333M', ini_get('memory_limit'));
            $this->assertSame(
                'guide_not_configured',
                $provider->refresh()->last_guide_error_code,
            );
        } finally {
            ini_set('memory_limit', (string) $originalLimit);
        }
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
