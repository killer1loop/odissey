<?php

namespace Tests\Feature\Media;

use App\Jobs\Media\EnrichMediaItem;
use App\Jobs\Media\FetchMediaCaptions;
use App\Models\MediaItem;
use App\Models\MediaSubtitle;
use App\Models\User;
use App\Services\Iptv\ConfidentialHttpFactory;
use App\Services\Iptv\HostAddressResolver;
use App\Services\Media\ArtworkManager;
use App\Services\Media\Captions\CaptionCandidate;
use App\Services\Media\Captions\CaptionStorage;
use App\Services\Media\Captions\OpenSubtitlesCaptionProvider;
use App\Services\Media\Captions\SubdlCaptionProvider;
use App\Services\Media\TmdbMetadataProvider;
use App\Services\Media\TvmazeMetadataProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Events\RequestSending;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Mockery\MockInterface;
use RuntimeException;
use Tests\TestCase;
use ZipArchive;

class CaptionSupportTest extends TestCase
{
    use RefreshDatabase;

    private string $directory;

    private string $transcodeDirectory;

    protected function setUp(): void
    {
        parent::setUp();
        if (! config('app.key')) {
            config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        }
        $this->directory = sys_get_temp_dir().'/odissey-captions-'.bin2hex(random_bytes(5));
        $this->transcodeDirectory = $this->directory.'-transcodes';
        config([
            'odissey.caption_path' => $this->directory,
            'odissey.transcode_min_free_bytes' => 0,
            'odissey.transcode_path' => $this->transcodeDirectory,
            'services.subdl.api_key' => 'free-key',
        ]);
        $http = new ConfidentialHttpFactory;
        Http::swap($http);
        $this->app->instance(ConfidentialHttpFactory::class, $http);
        $this->mock(
            HostAddressResolver::class,
            fn (MockInterface $mock) => $mock
                ->shouldReceive('resolve')
                ->andReturn(['93.184.216.34']),
        );
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->directory);
        File::deleteDirectory($this->transcodeDirectory);
        parent::tearDown();
    }

    public function test_subdl_search_uses_free_api_and_normalizes_episode_candidate(): void
    {
        Http::fake(['api.subdl.com/*' => Http::response([
            'status' => true, 'subtitles' => [[
                'unpack_files' => [[
                    'file_n_id' => 'caption-1', 'language' => 'EN', 'episode' => 2,
                    'name' => 'Episode.srt', 'url' => '/subtitle/pack/caption-1',
                ]],
            ]],
        ])]);
        $item = $this->item(['kind' => 'episode', 'series_title' => 'Example', 'season_number' => 1, 'episode_number' => 2, 'tmdb_id' => 42]);
        $results = app(SubdlCaptionProvider::class)->search($item, ['en']);
        $this->assertCount(1, $results);
        $this->assertSame('https://dl.subdl.com/subtitle/pack/caption-1', $results[0]->downloadUrl);
    }

    public function test_opensubtitles_api_headers_are_not_attached_to_download_candidates(): void
    {
        config(['services.opensubtitles.api_key' => 'private-open-key']);
        $requestEvents = 0;
        Event::listen(
            RequestSending::class,
            function () use (&$requestEvents): void {
                $requestEvents++;
            },
        );
        Http::fake([
            'api.opensubtitles.com/api/v1/subtitles*' => Http::response([
                'data' => [[
                    'attributes' => [
                        'language' => 'en',
                        'release' => 'Example release',
                        'files' => [['file_id' => 42]],
                    ],
                ]],
            ]),
            'api.opensubtitles.com/api/v1/download' => Http::response([
                'link' => 'https://dl.opensubtitles.com/file/42',
            ]),
        ]);

        $results = app(OpenSubtitlesCaptionProvider::class)->search(
            $this->item(),
            ['en'],
        );

        $this->assertCount(1, $results);
        $this->assertSame([], $results[0]->headers);
        $this->assertSame(0, $requestEvents);
        Http::assertSent(function ($request): bool {
            if (
                $request->method() !== 'GET'
                || ! str_starts_with(
                    $request->url(),
                    'https://api.opensubtitles.com/api/v1/subtitles?',
                )
            ) {
                return false;
            }

            parse_str(
                (string) parse_url($request->url(), PHP_URL_QUERY),
                $query,
            );

            return ($query['languages'] ?? null) === 'en'
                && ($query['query'] ?? null) === 'Example';
        });
        Http::assertSent(
            fn ($request): bool => $request->method() === 'POST'
                && $request->url()
                    === 'https://api.opensubtitles.com/api/v1/download'
                && ($request->data()['file_id'] ?? null) === 42,
        );
    }

    public function test_caption_provider_json_is_bounded_before_parsing(): void
    {
        config(['odissey.provider_json_max_bytes' => 1024]);
        Http::fake([
            'api.subdl.com/*' => Http::response([
                'status' => true,
                'padding' => str_repeat('x', 2048),
                'subtitles' => [[
                    'unpack_files' => [[
                        'file_n_id' => 'caption-oversized',
                        'language' => 'EN',
                        'name' => 'Oversized.srt',
                        'url' => '/subtitle/pack/caption-oversized',
                    ]],
                ]],
            ]),
        ]);

        $this->assertSame(
            [],
            app(SubdlCaptionProvider::class)->search($this->item(), ['en']),
        );
    }

    public function test_opensubtitles_api_redirect_is_not_followed_with_provider_headers(): void
    {
        config(['services.opensubtitles.api_key' => 'private-open-key']);
        Http::fake([
            'api.opensubtitles.com/*' => Http::response('', 302, [
                'Location' => 'https://untrusted.example.test/collect',
            ]),
            'untrusted.example.test/*' => Http::response(['data' => []]),
        ]);

        $results = app(OpenSubtitlesCaptionProvider::class)->search(
            $this->item(),
            ['en'],
        );

        $this->assertSame([], $results);
        Http::assertSentCount(1);
        Http::assertNotSent(
            fn ($request): bool => str_contains(
                $request->url(),
                'untrusted.example.test',
            ),
        );
    }

    public function test_downloaded_webvtt_is_private_and_available_to_the_media_owner(): void
    {
        $user = User::factory()->create();
        $item = $this->item([], $user);
        $candidate = new CaptionCandidate('subdl', 'caption-1', 'en', 'English', 'https://dl.subdl.com/subtitle/1');
        $path = app(CaptionStorage::class)->store($item, $candidate, "WEBVTT\n\n00:00:00.000 --> 00:00:01.000\nHello\n");
        $caption = MediaSubtitle::create(['media_item_id' => $item->id, 'provider' => 'subdl', 'external_id' => 'caption-1', 'language' => 'en', 'label' => 'English', 'path' => $path]);
        $this->get(route('media.captions.show', [$item, $caption]))->assertRedirect(route('login'));
        $this->actingAs($user)->get(route('media.captions.show', [$item, $caption]))->assertOk()->assertHeader('Content-Type', 'text/vtt; charset=UTF-8');
        $this->assertStringNotContainsString($path, $caption->getRawOriginal('path'));
    }

    public function test_caption_storage_enforces_the_global_media_asset_quota(): void
    {
        config(['odissey.media_asset_max_bytes' => 8]);
        $candidate = new CaptionCandidate(
            'subdl',
            'caption-quota',
            'en',
            'English',
            'https://dl.subdl.com/subtitle/quota',
        );

        try {
            app(CaptionStorage::class)->store(
                $this->item(),
                $candidate,
                "WEBVTT\n\n00:00:00.000 --> 00:00:01.000\nHello\n",
            );
            $this->fail('Expected the media asset quota to reject the caption.');
        } catch (RuntimeException $exception) {
            $this->assertSame(
                'media_asset_storage_quota_exceeded',
                $exception->getMessage(),
            );
        }

        $this->assertSame([], File::allFiles($this->directory));
    }

    public function test_caption_staging_files_are_not_double_counted_against_the_quota(): void
    {
        config(['odissey.media_asset_max_bytes' => 100]);
        $candidate = new CaptionCandidate(
            'subdl',
            'caption-tight-quota',
            'en',
            'English',
            'https://dl.subdl.com/subtitle/tight-quota',
        );

        $path = app(CaptionStorage::class)->store(
            $this->item(),
            $candidate,
            "WEBVTT\n\n00:00:00.000 --> 00:00:01.000\nHello\n",
        );

        $this->assertFileExists($path);
        $this->assertLessThanOrEqual(100, File::size($path));
        $this->assertCount(1, File::allFiles($this->directory));
    }

    public function test_caption_storage_cleans_up_an_invalid_archive(): void
    {
        $candidate = new CaptionCandidate(
            'subdl',
            'caption-invalid-archive',
            'en',
            'English',
            'https://dl.subdl.com/subtitle/archive',
        );

        try {
            app(CaptionStorage::class)->store(
                $this->item(),
                $candidate,
                "PK\x03\x04not-a-valid-zip",
            );
            $this->fail('Expected the invalid archive to be rejected.');
        } catch (RuntimeException $exception) {
            $this->assertSame('caption_archive_invalid', $exception->getMessage());
        }

        $this->assertSame([], File::allFiles($this->directory));
    }

    public function test_caption_storage_streams_a_valid_archive_entry_and_cleans_staging_files(): void
    {
        $caption = "WEBVTT\n\n"
            ."00:00:00.000 --> 00:00:01.000\n"
            ."Hello from a compressed archive\n"
            .str_repeat("NOTE test payload\n", 4096);
        $archivePath = tempnam(
            sys_get_temp_dir(),
            'odissey-caption-archive-',
        );
        $zip = new ZipArchive;
        $archiveOpen = false;

        try {
            $openResult = $zip->open(
                $archivePath,
                ZipArchive::CREATE | ZipArchive::OVERWRITE,
            );
            $this->assertTrue($openResult);
            $archiveOpen = $openResult === true;
            $this->assertTrue(
                $zip->addFromString('nested/english.vtt', $caption),
            );
            $this->assertTrue($zip->close());
            $archiveOpen = false;
            $candidate = new CaptionCandidate(
                'subdl',
                'caption-valid-archive',
                'en',
                'English',
                'https://dl.subdl.com/subtitle/archive',
            );

            $path = app(CaptionStorage::class)->store(
                $this->item(),
                $candidate,
                File::get($archivePath),
            );

            $this->assertSame($caption, File::get($path));
            $this->assertCount(1, File::allFiles($this->directory));
            $this->assertSame(
                [],
                File::isDirectory($this->transcodeDirectory)
                    ? File::allFiles($this->transcodeDirectory)
                    : [],
            );
        } finally {
            if ($archiveOpen) {
                $zip->close();
            }
            File::delete($archivePath);
        }
    }

    public function test_admin_can_store_provider_keys_encrypted_without_readback(): void
    {
        $admin = User::factory()->create(['is_admin' => true, 'is_active' => true]);
        $this->actingAs($admin)->put(route('media.admin.integrations.update'), [
            'tmdb_api_token' => 'private-tmdb-token',
            'subdl_api_key' => 'private-subdl-key',
            'opensubtitles_api_key' => 'private-open-key',
            'caption_languages' => 'en,it',
        ])->assertRedirect();
        $raw = DB::table('integration_settings')->pluck('value')->implode(' ');
        $this->assertStringNotContainsString('private-', $raw);
        $this->actingAs($admin)->get(route('media.admin.integrations.edit'))
            ->assertOk()->assertSee('configured')->assertDontSee('private-tmdb-token');
    }

    public function test_automatic_enrichment_skips_captions_without_a_provider(): void
    {
        Queue::fake();
        config([
            'services.subdl.api_key' => null,
            'services.opensubtitles.api_key' => null,
        ]);
        $item = $this->item(['kind' => 'movie']);
        $tmdb = $this->mock(
            TmdbMetadataProvider::class,
            fn (MockInterface $mock) => $mock
                ->shouldReceive('match')
                ->once()
                ->andReturn([]),
        );
        $tvmaze = $this->mock(TvmazeMetadataProvider::class);
        $tvmaze->shouldNotReceive('match');
        $artwork = $this->mock(
            ArtworkManager::class,
            fn (MockInterface $mock) => $mock
                ->shouldReceive('populate')
                ->once(),
        );

        (new EnrichMediaItem($item->id))->handle(
            $tmdb,
            $tvmaze,
            $artwork,
        );

        Queue::assertNotPushed(FetchMediaCaptions::class);
    }

    public function test_startup_prunes_only_unconfigured_caption_jobs(): void
    {
        config([
            'services.subdl.api_key' => null,
            'services.opensubtitles.api_key' => null,
        ]);
        $now = now()->timestamp;
        DB::table('jobs')->insert([
            [
                'queue' => 'media-enrichment',
                'payload' => '{"displayName":"App\\\\Jobs\\\\Media\\\\FetchMediaCaptions"}',
                'attempts' => 0,
                'reserved_at' => null,
                'available_at' => $now,
                'created_at' => $now,
            ],
            [
                'queue' => 'media-enrichment',
                'payload' => '{"displayName":"App\\\\Jobs\\\\Media\\\\EnrichMediaItem"}',
                'attempts' => 0,
                'reserved_at' => null,
                'available_at' => $now,
                'created_at' => $now,
            ],
        ]);

        $this->artisan('media:captions:prune-unconfigured')
            ->expectsOutput('Pruned 1 unconfigured caption job(s).')
            ->assertSuccessful();

        $this->assertDatabaseCount('jobs', 1);
        $this->assertStringContainsString(
            'EnrichMediaItem',
            DB::table('jobs')->value('payload'),
        );
    }

    private function item(array $metadata = [], ?User $user = null): MediaItem
    {
        $user ??= User::factory()->create(['is_admin' => true]);

        return MediaItem::create([
            'user_id' => $user->id, 'title' => 'Example', 'media_kind' => 'video',
            'source_type' => 'e2e', 'source_locator' => '/tmp/example.mp4',
            'mime_type' => 'video/mp4', 'container' => 'mp4', 'metadata' => $metadata,
        ]);
    }
}
