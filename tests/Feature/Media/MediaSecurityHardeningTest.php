<?php

namespace Tests\Feature\Media;

use App\Jobs\Media\TranscodeMediaToHls;
use App\Models\MediaItem;
use App\Models\MediaSource;
use App\Models\PlaybackHistory;
use App\Models\TranscodeSession;
use App\Models\User;
use App\Services\Media\DirectStreamConcurrencyGate;
use App\Services\Media\FfmpegArguments;
use App\Services\Media\FfmpegRunner;
use App\Services\Media\MediaAssetStorage;
use App\Services\Media\SourceMaterializer;
use App\Services\Media\Sources\MediaSourceAdapter;
use App\Services\Media\Sources\MediaSourceRegistry;
use App\Services\Media\Sources\SourceResponse;
use App\Services\Media\TranscodeConcurrencyGate;
use App\Services\Media\TranscodeStorage;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class MediaSecurityHardeningTest extends TestCase
{
    use RefreshDatabase;

    private string $temporaryPath;

    protected function setUp(): void
    {
        parent::setUp();

        if (! config('app.key')) {
            config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        }

        $this->temporaryPath = sys_get_temp_dir().'/odissey-security-tests-'.Str::uuid();
        File::ensureDirectoryExists($this->temporaryPath);
        config([
            'odissey.transcode_min_free_bytes' => 0,
            'odissey.transcode_path' => $this->temporaryPath.'/transcodes',
        ]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->temporaryPath);

        parent::tearDown();
    }

    public function test_remote_content_type_cannot_create_same_origin_active_html(): void
    {
        $user = User::factory()->create();
        [$source, $item] = $this->remoteMedia($user, mimeType: 'video/mp4');
        $adapter = $this->adapterReturning(
            new SourceResponse(
                '<script>document.body.dataset.compromised=1</script>',
                200,
                53,
                'text/html',
            ),
        );
        $this->app->instance(
            MediaSourceRegistry::class,
            $this->registryReturning($adapter),
        );

        $this->actingAs($user)
            ->get(route('media.direct', $item))
            ->assertOk()
            ->assertHeader('Content-Type', 'video/mp4')
            ->assertHeader('Content-Security-Policy', "sandbox; default-src 'none'")
            ->assertHeader('X-Content-Type-Options', 'nosniff');

        $item->update(['mime_type' => 'text/html']);

        $this->actingAs($user)
            ->get(route('media.direct', $item))
            ->assertOk()
            ->assertHeader('Content-Type', 'application/octet-stream')
            ->assertHeader('Content-Disposition', 'attachment; filename=movie.mp4');
    }

    public function test_remote_stream_cannot_exceed_the_catalogued_object_size(): void
    {
        $user = User::factory()->create();
        [, $item] = $this->remoteMedia($user, sizeBytes: 4);
        $adapter = $this->adapterReturning(
            new SourceResponse(
                Utils::streamFor('oversized'),
                200,
                9,
                'video/mp4',
            ),
        );
        $this->app->instance(
            MediaSourceRegistry::class,
            $this->registryReturning($adapter),
        );

        $this->actingAs($user)
            ->get(route('media.direct', $item))
            ->assertStatus(502);
    }

    public function test_remote_stream_admission_rejects_before_source_open(): void
    {
        config([
            'odissey.remote_stream_global_concurrency' => 1,
            'odissey.remote_stream_source_concurrency' => 1,
            'odissey.remote_stream_user_concurrency' => 1,
        ]);
        $user = User::factory()->create();
        [$source, $item] = $this->remoteMedia($user);
        $gate = app(DirectStreamConcurrencyGate::class);
        $held = $gate->acquire(
            (string) $user->getKey(),
            (string) $source->getKey(),
        );
        $this->assertNotNull($held);
        $adapter = Mockery::mock(MediaSourceAdapter::class);
        $adapter->shouldNotReceive('open');
        $this->app->instance(
            MediaSourceRegistry::class,
            $this->registryReturning($adapter),
        );

        try {
            $this->actingAs($user)
                ->get(route('media.direct', $item))
                ->assertTooManyRequests()
                ->assertHeader('Retry-After', '1');
        } finally {
            $held?->release();
        }
    }

    public function test_remote_stream_holds_admission_until_stream_completion(): void
    {
        config([
            'odissey.remote_stream_global_concurrency' => 1,
            'odissey.remote_stream_source_concurrency' => 1,
            'odissey.remote_stream_user_concurrency' => 1,
        ]);
        $user = User::factory()->create();
        [$source, $item] = $this->remoteMedia($user, sizeBytes: 14);
        $adapter = $this->adapterReturning(
            new SourceResponse(
                Utils::streamFor('bounded-stream'),
                200,
                14,
                'video/mp4',
            ),
        );
        $this->app->instance(
            MediaSourceRegistry::class,
            $this->registryReturning($adapter),
        );
        $gate = app(DirectStreamConcurrencyGate::class);

        $response = $this->actingAs($user)
            ->get(route('media.direct', $item))
            ->assertOk()
            ->assertStreamed();

        $this->assertNull($gate->acquire(
            (string) $user->getKey(),
            (string) $source->getKey(),
        ));
        $response->assertStreamedContent('bounded-stream');

        $afterCompletion = $gate->acquire(
            (string) $user->getKey(),
            (string) $source->getKey(),
        );
        $this->assertNotNull($afterCompletion);
        $afterCompletion?->release();
    }

    public function test_remote_stream_open_failure_releases_admission(): void
    {
        config([
            'odissey.remote_stream_global_concurrency' => 1,
            'odissey.remote_stream_source_concurrency' => 1,
            'odissey.remote_stream_user_concurrency' => 1,
        ]);
        $user = User::factory()->create();
        [$source, $item] = $this->remoteMedia($user);
        $adapter = new class implements MediaSourceAdapter
        {
            public int $openCalls = 0;

            public function objects(MediaSource $source): iterable
            {
                return [];
            }

            public function capabilities(MediaSource $source): array
            {
                return [];
            }

            public function open(
                MediaSource $source,
                string $locator,
                ?int $start,
                ?int $end,
            ): SourceResponse {
                $this->openCalls++;

                throw new RuntimeException('synthetic_source_open_failure');
            }

            public function localPath(
                MediaSource $source,
                string $locator,
            ): ?string {
                return null;
            }
        };
        $this->app->instance(
            MediaSourceRegistry::class,
            $this->registryReturning($adapter),
        );
        $this->withoutExceptionHandling();

        try {
            $this->actingAs($user)
                ->get(route('media.direct', $item));
            $this->fail('The synthetic source open should fail.');
        } catch (RuntimeException $exception) {
            $this->assertSame(
                'synthetic_source_open_failure',
                $exception->getMessage(),
            );
        } finally {
            $this->withExceptionHandling();
        }

        $this->assertSame(1, $adapter->openCalls);
        $replacement = app(DirectStreamConcurrencyGate::class)->acquire(
            (string) $user->getKey(),
            (string) $source->getKey(),
        );
        $this->assertNotNull($replacement);
        $replacement?->release();
    }

    public function test_remote_stream_read_failure_releases_admission_and_closes_body(): void
    {
        config([
            'odissey.remote_stream_global_concurrency' => 1,
            'odissey.remote_stream_source_concurrency' => 1,
            'odissey.remote_stream_user_concurrency' => 1,
        ]);
        $user = User::factory()->create();
        [$source, $item] = $this->remoteMedia($user, sizeBytes: 4);
        $body = new class
        {
            public bool $closed = false;

            public function eof(): bool
            {
                return false;
            }

            public function read(int $length): string
            {
                throw new RuntimeException('synthetic_stream_read_failure');
            }

            public function close(): void
            {
                $this->closed = true;
            }
        };
        $adapter = $this->adapterReturning(
            new SourceResponse($body, 200, 4, 'video/mp4'),
        );
        $this->app->instance(
            MediaSourceRegistry::class,
            $this->registryReturning($adapter),
        );
        $gate = app(DirectStreamConcurrencyGate::class);
        $response = $this->actingAs($user)
            ->get(route('media.direct', $item))
            ->assertOk()
            ->assertStreamed();
        $this->assertNull($gate->acquire(
            (string) $user->getKey(),
            (string) $source->getKey(),
        ));

        ob_start();
        try {
            $response->baseResponse->sendContent();
            $this->fail('The synthetic stream read should fail.');
        } catch (RuntimeException $exception) {
            $this->assertSame(
                'synthetic_stream_read_failure',
                $exception->getMessage(),
            );
        } finally {
            ob_end_clean();
        }

        $this->assertTrue($body->closed);
        $replacement = $gate->acquire(
            (string) $user->getKey(),
            (string) $source->getKey(),
        );
        $this->assertNotNull($replacement);
        $replacement?->release();
    }

    public function test_local_source_ranges_return_correct_200_206_and_416_metadata(): void
    {
        $user = User::factory()->create();
        $path = $this->temporaryPath.'/range-source.mp4';
        File::put($path, '0123456789');
        config(['odissey.local_source_roots' => [$this->temporaryPath]]);
        $source = MediaSource::query()->create([
            'name' => 'Range source',
            'type' => MediaSource::TYPE_LOCAL,
            'configuration' => ['path' => $this->temporaryPath],
            'capabilities' => ['range' => true],
            'enabled' => true,
        ]);
        $item = MediaItem::query()->create([
            'user_id' => $user->id,
            'media_source_id' => $source->id,
            'stable_id' => hash('sha256', 'range-source.mp4'),
            'title' => 'range-source.mp4',
            'relative_path' => 'range-source.mp4',
            'source_type' => MediaSource::TYPE_LOCAL,
            'source_locator' => 'range-source.mp4',
            'mime_type' => 'video/mp4',
            'size_bytes' => 10,
            'requires_transcode' => false,
        ]);

        $this->actingAs($user)
            ->get(route('media.direct', $item))
            ->assertOk()
            ->assertHeaderMissing('Content-Range')
            ->assertStreamedContent('0123456789');

        $this->actingAs($user)
            ->withHeader('Range', 'bytes=2-5')
            ->get(route('media.direct', $item))
            ->assertStatus(206)
            ->assertHeader('Content-Range', 'bytes 2-5/10')
            ->assertStreamedContent('2345');

        $this->actingAs($user)
            ->withHeader('Range', 'bytes=-4')
            ->get(route('media.direct', $item))
            ->assertStatus(206)
            ->assertHeader('Content-Range', 'bytes 6-9/10')
            ->assertStreamedContent('6789');

        $this->actingAs($user)
            ->withHeader('Range', 'bytes=999-')
            ->get(route('media.direct', $item))
            ->assertStatus(416)
            ->assertHeader('Content-Range', 'bytes */10');
    }

    public function test_remote_materialization_stops_at_available_capacity_and_removes_partial_files(): void
    {
        $user = User::factory()->create();
        [, $item] = $this->remoteMedia($user, sizeBytes: 0);
        config([
            'odissey.remote_materialize_max_source_bytes' => 1024,
            'odissey.transcode_max_bytes' => 16,
        ]);
        $adapter = $this->adapterReturning(
            new SourceResponse(
                Utils::streamFor(str_repeat('A', 32)),
                200,
                0,
                'video/mp4',
            ),
        );
        $materializer = new SourceMaterializer(
            $this->registryReturning($adapter),
            app(TranscodeStorage::class),
        );

        try {
            $materializer->materialize($item->load('source'));
            $this->fail('The oversized materialization should have failed.');
        } catch (RuntimeException $exception) {
            $this->assertSame('remote_source_too_large', $exception->getMessage());
        }

        $directory = app(TranscodeStorage::class)
            ->transientDirectory('sources');
        $this->assertSame([], File::isDirectory($directory) ? File::files($directory) : []);
    }

    public function test_transcode_materialization_uses_its_explicit_large_source_limit(): void
    {
        $catalogBytes = 17 * 1024 * 1024 * 1024;
        $maximumBytes = 32 * 1024 * 1024 * 1024;
        $source = new MediaSource;
        $session = new TranscodeSession;
        $adapter = Mockery::mock(MediaSourceAdapter::class);
        $registry = Mockery::mock(MediaSourceRegistry::class);
        $registry->shouldReceive('for')
            ->once()
            ->with($source)
            ->andReturn($adapter);
        $storage = Mockery::mock(TranscodeStorage::class);
        $storage->shouldReceive('sourcePath')
            ->once()
            ->with($session, 'mp4')
            ->andReturn($this->temporaryPath.'/source.mp4');
        $storage->shouldReceive('reserveSourceBytes')
            ->once()
            ->with($maximumBytes, $catalogBytes)
            ->andReturnNull();
        $materializer = new SourceMaterializer($registry, $storage);

        try {
            $materializer->materializeObjectForTranscode(
                $session,
                $source,
                'large.mp4',
                $catalogBytes,
                'mp4',
                $maximumBytes,
            );
            $this->fail('Unavailable transcode capacity should be rejected.');
        } catch (RuntimeException $exception) {
            $this->assertSame(
                'remote_source_capacity_exhausted',
                $exception->getMessage(),
            );
        }
    }

    public function test_remote_materialization_reservations_prevent_concurrent_quota_overcommit(): void
    {
        config(['odissey.transcode_max_bytes' => 16]);
        $storage = app(TranscodeStorage::class);
        $reservation = $storage->reserveSourceBytes(16);

        $this->assertNotNull($reservation);

        try {
            $directory = $storage->transientDirectory(
                'sources',
                create: true,
            );
            File::put($directory.'/materialized.bin', str_repeat('A', 8));
            $reservation->consume(8);

            $this->assertSame(8, $storage->reservedBytes());
            $this->assertNull($storage->reserveSourceBytes(16));
            $this->assertSame(16, $storage->bytesUsed());
        } finally {
            $reservation?->release();
            File::delete(
                $storage->transientDirectory('sources')
                    .'/materialized.bin',
            );
        }

        $this->assertSame(0, $storage->reservedBytes());
    }

    public function test_transcode_failure_always_removes_a_materialized_temporary_source(): void
    {
        $user = User::factory()->create();
        $path = $this->temporaryPath.'/materialized-source.mkv';
        File::put($path, 'temporary source');
        $item = MediaItem::query()->create([
            'user_id' => $user->id,
            'title' => 'Temporary transcode',
            'source_type' => 'local',
            'source_locator' => $this->temporaryPath.'/original.mkv',
            'mime_type' => 'video/x-matroska',
            'requires_transcode' => true,
        ]);
        $session = TranscodeSession::query()->create([
            'user_id' => $user->id,
            'media_item_id' => $item->id,
            'status' => TranscodeSession::STATUS_PENDING,
        ]);
        $materializer = Mockery::mock(SourceMaterializer::class);
        $materializer->shouldReceive('materialize')->once()->andReturn([
            'path' => $path,
            'temporary' => true,
        ]);
        $this->app->instance(SourceMaterializer::class, $materializer);
        $runner = new class extends FfmpegRunner
        {
            public function run(
                array $arguments,
                int $timeoutSeconds,
                ?callable $shouldContinue = null,
            ): void {
                throw new RuntimeException('synthetic codec failure');
            }
        };

        (new TranscodeMediaToHls($session->id))->handle(
            $runner,
            app(FfmpegArguments::class),
            app(TranscodeStorage::class),
            app(TranscodeConcurrencyGate::class),
        );

        $this->assertFalse(File::exists($path));
        $this->assertSame(
            TranscodeSession::STATUS_FAILED,
            $session->refresh()->status,
        );
    }

    public function test_subtitle_extraction_refuses_work_when_media_capacity_is_busy(): void
    {
        $user = User::factory()->create();
        $item = MediaItem::query()->create([
            'user_id' => $user->id,
            'title' => 'Subtitle fixture',
            'source_type' => 'local',
            'source_locator' => $this->temporaryPath.'/source.mkv',
            'mime_type' => 'video/x-matroska',
            'requires_transcode' => true,
            'metadata' => [
                'technical' => [
                    'subtitle_tracks' => [['index' => 0]],
                ],
            ],
        ]);
        $this->app->instance(
            TranscodeConcurrencyGate::class,
            new class extends TranscodeConcurrencyGate
            {
                public function acquire(int $leaseSeconds): ?Lock
                {
                    return null;
                }
            },
        );

        $this->actingAs($user)
            ->get(route('media.subtitles', [$item, 0]))
            ->assertTooManyRequests();
    }

    public function test_progress_history_is_aggregated_and_transcode_queue_is_bounded(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $item = MediaItem::query()->create([
            'user_id' => $user->id,
            'title' => 'Progress fixture',
            'source_type' => 'local',
            'source_locator' => $this->temporaryPath.'/movie.mp4',
            'mime_type' => 'video/mp4',
            'duration_ms' => 60000,
            'requires_transcode' => false,
        ]);

        foreach ([1000, 2000, 3000] as $index => $position) {
            $this->actingAs($user)->putJson(route('media.progress', $item), [
                'sequence' => $index + 1,
                'position_ms' => $position,
                'duration_ms' => 60000,
            ])->assertOk();
        }

        $this->assertSame(
            1,
            PlaybackHistory::query()->where('event', 'progress')->count(),
        );

        config(['odissey.max_pending_transcodes_per_user' => 1]);
        $queuedItem = MediaItem::query()->create([
            'user_id' => $user->id,
            'title' => 'Queued transcode',
            'source_type' => 'local',
            'source_locator' => $this->temporaryPath.'/queued.mkv',
            'mime_type' => 'video/x-matroska',
            'requires_transcode' => true,
        ]);
        TranscodeSession::query()->create([
            'user_id' => $user->id,
            'media_item_id' => $queuedItem->id,
            'status' => TranscodeSession::STATUS_PENDING,
        ]);
        $secondItem = MediaItem::query()->create([
            'user_id' => $user->id,
            'title' => 'Rejected transcode',
            'source_type' => 'local',
            'source_locator' => $this->temporaryPath.'/rejected.mkv',
            'mime_type' => 'video/x-matroska',
            'requires_transcode' => true,
        ]);

        $this->actingAs($user)
            ->post(route('media.transcodes.store', $secondItem))
            ->assertTooManyRequests();
    }

    public function test_asset_publication_is_serialized_across_workers(): void
    {
        config(['odissey.media_asset_lock_wait_seconds' => 1]);
        $held = Cache::lock('odissey:media:asset-storage', 30);
        $this->assertTrue($held->get());

        try {
            app(MediaAssetStorage::class)->synchronized(
                fn (): bool => $this->fail(
                    'A concurrent asset publisher must not enter the critical section.',
                ),
            );
            $this->fail('Concurrent asset publication should be rejected.');
        } catch (RuntimeException $exception) {
            $this->assertSame(
                'media_asset_storage_busy',
                $exception->getMessage(),
            );
        } finally {
            $held->release();
        }
    }

    public function test_empty_artwork_cleanup_preserves_files_and_symlinks(): void
    {
        $root = $this->temporaryPath.'/artwork-prune';
        $outside = $this->temporaryPath.'/outside-artwork-prune';
        File::ensureDirectoryExists($root.'/empty');
        File::ensureDirectoryExists($root.'/with-poster');
        File::ensureDirectoryExists($outside);
        File::put($root.'/with-poster/poster.jpg', 'poster');
        File::put($outside.'/preserved.txt', 'preserved');
        config(['odissey.artwork_path' => $root]);

        $symlinkCreated = @symlink($outside, $root.'/linked');

        $this->artisan('media:artwork:prune-empty')
            ->expectsOutput('Pruned 1 empty artwork directory.')
            ->assertSuccessful();

        $this->assertDirectoryDoesNotExist($root.'/empty');
        $this->assertFileExists($root.'/with-poster/poster.jpg');
        $this->assertFileExists($outside.'/preserved.txt');
        if ($symlinkCreated) {
            $this->assertTrue(is_link($root.'/linked'));
        }
    }

    public function test_media_item_cleanup_never_follows_symlinked_asset_directories(): void
    {
        $artworkRoot = $this->temporaryPath.'/artwork';
        $captionRoot = $this->temporaryPath.'/captions';
        $outside = $this->temporaryPath.'/outside-assets';
        File::ensureDirectoryExists($artworkRoot);
        File::ensureDirectoryExists($captionRoot);
        File::ensureDirectoryExists($outside);
        File::put($outside.'/must-survive.txt', 'preserved');
        config([
            'odissey.artwork_path' => $artworkRoot,
            'odissey.caption_path' => $captionRoot,
        ]);
        $item = MediaItem::query()->create([
            'user_id' => User::factory()->create()->id,
            'title' => 'Symlink cleanup fixture',
            'source_type' => 'local',
            'source_locator' => $this->temporaryPath.'/fixture.mp4',
            'mime_type' => 'video/mp4',
            'requires_transcode' => false,
        ]);

        if (
            ! @symlink($outside, $artworkRoot.'/'.$item->id)
            || ! @symlink($outside, $captionRoot.'/'.$item->id)
        ) {
            $this->markTestSkipped('Filesystem symlinks are unavailable.');
        }

        $item->delete();

        $this->assertFileExists($outside.'/must-survive.txt');
        $this->assertTrue(is_link($artworkRoot.'/'.$item->id));
        $this->assertTrue(is_link($captionRoot.'/'.$item->id));
    }

    public function test_transcode_cleanup_rejects_a_symlinked_session_directory(): void
    {
        $root = $this->temporaryPath.'/transcodes';
        $outside = $this->temporaryPath.'/outside-transcodes';
        File::ensureDirectoryExists($root);
        File::ensureDirectoryExists($outside);
        File::put($outside.'/must-survive.ts', 'preserved');
        $sessionId = strtolower((string) Str::ulid());

        if (! @symlink($outside, $root.'/'.$sessionId)) {
            $this->markTestSkipped('Filesystem symlinks are unavailable.');
        }

        try {
            app(TranscodeStorage::class)->deleteById($sessionId);
            $this->fail('A symlinked transcode directory must be rejected.');
        } catch (RuntimeException $exception) {
            $this->assertSame(
                'The transcode directory is unsafe.',
                $exception->getMessage(),
            );
        }

        $this->assertFileExists($outside.'/must-survive.ts');
    }

    /**
     * @return array{MediaSource, MediaItem}
     */
    private function remoteMedia(
        User $user,
        string $mimeType = 'video/mp4',
        int $sizeBytes = 53,
    ): array {
        $source = MediaSource::query()->create([
            'name' => 'Security remote '.Str::uuid(),
            'type' => MediaSource::TYPE_S3,
            'configuration' => ['endpoint' => 'https://media.example.test'],
            'capabilities' => ['range' => true],
            'enabled' => true,
        ]);
        $item = MediaItem::query()->create([
            'user_id' => $user->id,
            'media_source_id' => $source->id,
            'stable_id' => hash('sha256', (string) Str::uuid()),
            'title' => 'movie.mp4',
            'relative_path' => 'movie.mp4',
            'source_type' => MediaSource::TYPE_S3,
            'source_locator' => 'movie.mp4',
            'mime_type' => $mimeType,
            'size_bytes' => $sizeBytes,
            'requires_transcode' => false,
        ]);

        return [$source, $item];
    }

    private function adapterReturning(SourceResponse $response): MediaSourceAdapter
    {
        return new class($response) implements MediaSourceAdapter
        {
            public function __construct(
                private readonly SourceResponse $response,
            ) {}

            public function objects(MediaSource $source): iterable
            {
                return [];
            }

            public function capabilities(MediaSource $source): array
            {
                return ['range' => true, 'seekable' => true, 'read_only' => true];
            }

            public function open(
                MediaSource $source,
                string $locator,
                ?int $start,
                ?int $end,
            ): SourceResponse {
                return $this->response;
            }

            public function localPath(MediaSource $source, string $locator): ?string
            {
                return null;
            }
        };
    }

    private function registryReturning(MediaSourceAdapter $adapter): MediaSourceRegistry
    {
        return new class($adapter) extends MediaSourceRegistry
        {
            public function __construct(
                private readonly MediaSourceAdapter $adapter,
            ) {}

            public function for(MediaSource $source): MediaSourceAdapter
            {
                return $this->adapter;
            }
        };
    }
}
