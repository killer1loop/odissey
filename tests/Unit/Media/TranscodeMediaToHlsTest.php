<?php

namespace Tests\Unit\Media;

use App\Jobs\Media\TranscodeMediaToHls;
use App\Models\MediaItem;
use App\Models\MediaSource;
use App\Models\TranscodeSession;
use App\Models\User;
use App\Services\Media\FfmpegArguments;
use App\Services\Media\FfmpegRunner;
use App\Services\Media\Sources\MediaSourceAdapter;
use App\Services\Media\Sources\MediaSourceRegistry;
use App\Services\Media\Sources\SourceResponse;
use App\Services\Media\TranscodeConcurrencyGate;
use App\Services\Media\TranscodeStorage;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Psr\Http\Message\StreamInterface;
use RuntimeException;
use Tests\TestCase;

class TranscodeMediaToHlsTest extends TestCase
{
    use RefreshDatabase;

    private string $temporaryPath;

    protected function setUp(): void
    {
        parent::setUp();

        if (! config('app.key')) {
            config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        }

        $this->temporaryPath = sys_get_temp_dir().'/odissey-transcode-tests-'.Str::uuid();
        File::ensureDirectoryExists($this->temporaryPath);
        config([
            'odissey.max_transcodes' => 1,
            'odissey.transcode_max_bytes' => 1024 * 1024,
            'odissey.transcode_path' => $this->temporaryPath.'/transcodes',
        ]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->temporaryPath);

        parent::tearDown();
    }

    public function test_job_generates_vod_hls_and_marks_the_session_ready(): void
    {
        [$session, $sourcePath] = $this->makeTranscodeSession();
        File::put($sourcePath, 'unsupported source');
        $runner = new class extends FfmpegRunner
        {
            public function run(
                array $arguments,
                int $timeoutSeconds,
                ?callable $shouldContinue = null,
            ): void {
                $manifest = $arguments[array_key_last($arguments)];
                $patternIndex = array_search('-hls_segment_filename', $arguments, true);
                $segmentPattern = $arguments[$patternIndex + 1];

                File::put(
                    $manifest,
                    "#EXTM3U\n#EXTINF:4.0,\nsegment-00000.ts\n",
                );
                File::put(str_replace('%05d', '00000', $segmentPattern), 'segment');
            }
        };

        (new TranscodeMediaToHls($session->getKey()))->handle(
            $runner,
            app(FfmpegArguments::class),
            app(TranscodeStorage::class),
            app(TranscodeConcurrencyGate::class),
        );

        $session->refresh();
        $this->assertSame(TranscodeSession::STATUS_READY, $session->status);
        $this->assertSame('index.m3u8', $session->manifest_relative_path);
        $this->assertNull($session->error_code);
        $this->assertTrue(app(TranscodeStorage::class)->hasCompleteOutput($session));
    }

    public function test_unexpected_process_details_are_replaced_with_a_sanitized_error_code(): void
    {
        [$session, $sourcePath] = $this->makeTranscodeSession();
        File::put($sourcePath, 'unsupported source');
        $runner = new class extends FfmpegRunner
        {
            public function run(
                array $arguments,
                int $timeoutSeconds,
                ?callable $shouldContinue = null,
            ): void {
                $manifest = $arguments[array_key_last($arguments)];
                File::put($manifest, 'partial output containing a secret path');

                throw new RuntimeException('secret source path and raw process output');
            }
        };

        (new TranscodeMediaToHls($session->getKey()))->handle(
            $runner,
            app(FfmpegArguments::class),
            app(TranscodeStorage::class),
            app(TranscodeConcurrencyGate::class),
        );

        $session->refresh();
        $this->assertSame(TranscodeSession::STATUS_FAILED, $session->status);
        $this->assertSame('transcode_internal', $session->error_code);
        $this->assertStringNotContainsString('secret', (string) $session->error_code);
        $this->assertFalse(
            File::isDirectory(config('odissey.transcode_path').'/'.$session->getKey()),
        );
    }

    public function test_late_runner_failure_keeps_a_watchdog_completed_output(): void
    {
        [$session, $sourcePath] = $this->makeTranscodeSession();
        File::put($sourcePath, 'unsupported source');
        $runner = new class extends FfmpegRunner
        {
            public function run(
                array $arguments,
                int $timeoutSeconds,
                ?callable $shouldContinue = null,
            ): void {
                $manifest = $arguments[array_key_last($arguments)];
                File::put(
                    $manifest,
                    "#EXTM3U\n#EXTINF:4.0,\nsegment-00000.ts\n",
                );
                File::put(
                    str_replace(
                        '%05d',
                        '00000',
                        $arguments[array_search('-hls_segment_filename', $arguments, true) + 1],
                    ),
                    'segment',
                );

                if ($shouldContinue !== null) {
                    $shouldContinue();
                }

                throw new RuntimeException('late shutdown failure');
            }
        };

        (new TranscodeMediaToHls($session->getKey()))->handle(
            $runner,
            app(FfmpegArguments::class),
            app(TranscodeStorage::class),
            app(TranscodeConcurrencyGate::class),
        );

        $session->refresh();
        $this->assertSame(TranscodeSession::STATUS_READY, $session->status);
        $this->assertNotNull($session->finished_at);
        $this->assertTrue(
            File::isFile(config('odissey.transcode_path').'/'.$session->getKey().'/index.m3u8'),
            'A watchdog-completed output must survive a late runner failure.',
        );
    }

    public function test_session_becomes_playable_after_the_first_hls_segment(): void
    {
        [$session, $sourcePath] = $this->makeTranscodeSession();
        File::put($sourcePath, 'unsupported source');
        $runner = new class($session) extends FfmpegRunner
        {
            public ?string $statusDuringRun = null;

            public bool $finishedDuringRun = true;

            public function __construct(
                private readonly TranscodeSession $session,
            ) {}

            public function run(
                array $arguments,
                int $timeoutSeconds,
                ?callable $shouldContinue = null,
            ): void {
                $manifest = $arguments[array_key_last($arguments)];
                $patternIndex = array_search(
                    '-hls_segment_filename',
                    $arguments,
                    true,
                );
                $segmentPattern = $arguments[$patternIndex + 1];
                File::put(
                    $manifest,
                    "#EXTM3U\n#EXTINF:4.0,\nsegment-00000.ts\n",
                );
                File::put(
                    str_replace('%05d', '00000', $segmentPattern),
                    'segment',
                );

                $shouldContinue?->__invoke();
                $this->session->refresh();
                $this->statusDuringRun = $this->session->status;
                $this->finishedDuringRun = $this->session->finished_at !== null;
            }
        };

        (new TranscodeMediaToHls($session->getKey()))->handle(
            $runner,
            app(FfmpegArguments::class),
            app(TranscodeStorage::class),
            app(TranscodeConcurrencyGate::class),
        );

        $this->assertSame(
            TranscodeSession::STATUS_READY,
            $runner->statusDuringRun,
        );
        // Watchdog promotion completes the session so late runner failures
        // cannot delete the playable output.
        $this->assertTrue($runner->finishedDuringRun);
        $this->assertNotNull($session->refresh()->finished_at);
    }

    public function test_large_sequential_remote_sources_use_bounded_ffmpeg_stdin(): void
    {
        $sourceBytes = 3 * 1024 * 1024 * 1024 + 1;
        $user = User::factory()->create(['is_active' => true]);
        $source = MediaSource::query()->create([
            'name' => 'Remote transcode source',
            'type' => MediaSource::TYPE_S3,
            'configuration' => ['endpoint' => 'https://media.example.test'],
            'capabilities' => ['range' => true],
            'enabled' => true,
        ]);
        $item = MediaItem::query()->create([
            'user_id' => $user->getKey(),
            'media_source_id' => $source->getKey(),
            'stable_id' => hash('sha256', (string) Str::uuid()),
            'title' => 'Remote transcode fixture',
            'relative_path' => 'fixture.mkv',
            'source_type' => MediaSource::TYPE_S3,
            'source_locator' => 'fixture.mkv',
            'mime_type' => 'video/x-matroska',
            'size_bytes' => $sourceBytes,
            'requires_transcode' => true,
        ]);
        $session = TranscodeSession::query()->create([
            'user_id' => $user->getKey(),
            'media_item_id' => $item->getKey(),
            'status' => TranscodeSession::STATUS_PENDING,
        ]);
        $adapter = new class($sourceBytes) implements MediaSourceAdapter
        {
            public function __construct(private readonly int $sourceBytes) {}

            public function objects(MediaSource $source): iterable
            {
                return [];
            }

            public function capabilities(MediaSource $source): array
            {
                return ['range' => true];
            }

            public function open(
                MediaSource $source,
                string $locator,
                ?int $start,
                ?int $end,
            ): SourceResponse {
                return new SourceResponse(
                    Utils::streamFor('streamed media bytes'),
                    200,
                    $this->sourceBytes,
                    'video/x-matroska',
                );
            }

            public function localPath(
                MediaSource $source,
                string $locator,
            ): ?string {
                return null;
            }
        };
        $registry = new class($adapter) extends MediaSourceRegistry
        {
            public function __construct(
                private readonly MediaSourceAdapter $adapter,
            ) {}

            public function for(MediaSource $source): MediaSourceAdapter
            {
                return $this->adapter;
            }
        };
        $runner = new class extends FfmpegRunner
        {
            public ?string $sourceArgument = null;

            public string $inputBytes = '';

            public function runWithInput(
                array $arguments,
                int $timeoutSeconds,
                mixed $input,
                ?callable $shouldContinue = null,
            ): void {
                $inputIndex = array_search('-i', $arguments, true);
                $this->sourceArgument = $arguments[$inputIndex + 1];
                $this->inputBytes = $input instanceof StreamInterface
                    ? $input->getContents()
                    : stream_get_contents($input);
                $manifest = $arguments[array_key_last($arguments)];
                $patternIndex = array_search(
                    '-hls_segment_filename',
                    $arguments,
                    true,
                );
                File::put(
                    $manifest,
                    "#EXTM3U\n#EXTINF:4.0,\nsegment-00000.ts\n",
                );
                File::put(
                    str_replace('%05d', '00000', $arguments[$patternIndex + 1]),
                    'segment',
                );
                $shouldContinue?->__invoke();
            }
        };

        (new TranscodeMediaToHls($session->getKey()))->handle(
            $runner,
            app(FfmpegArguments::class),
            app(TranscodeStorage::class),
            app(TranscodeConcurrencyGate::class),
            $registry,
        );

        $this->assertSame('pipe:0', $runner->sourceArgument);
        $this->assertSame('streamed media bytes', $runner->inputBytes);
        $this->assertSame(
            TranscodeSession::STATUS_READY,
            $session->refresh()->status,
        );
    }

    public function test_job_is_released_without_starting_when_all_concurrency_slots_are_busy(): void
    {
        [$session, $sourcePath] = $this->makeTranscodeSession();
        File::put($sourcePath, 'unsupported source');
        $runner = new class extends FfmpegRunner
        {
            public bool $wasCalled = false;

            public function run(
                array $arguments,
                int $timeoutSeconds,
                ?callable $shouldContinue = null,
            ): void {
                $this->wasCalled = true;
            }
        };
        $concurrency = new class extends TranscodeConcurrencyGate
        {
            public function acquire(int $leaseSeconds): ?Lock
            {
                return null;
            }
        };
        $job = (new TranscodeMediaToHls($session->getKey()))
            ->withFakeQueueInteractions();

        $job->handle(
            $runner,
            app(FfmpegArguments::class),
            app(TranscodeStorage::class),
            $concurrency,
        );

        $job->assertReleased(5);
        $this->assertFalse($runner->wasCalled);
        $this->assertSame(TranscodeSession::STATUS_PENDING, $session->refresh()->status);
    }

    public function test_active_playback_extends_an_expiring_hls_lease(): void
    {
        [$session, $sourcePath] = $this->makeTranscodeSession();
        File::put($sourcePath, 'unsupported source');
        $session->forceFill([
            'status' => TranscodeSession::STATUS_READY,
            'manifest_relative_path' => 'index.m3u8',
            'finished_at' => now()->subMinutes(40),
            'expires_at' => now()->addSeconds(30),
        ])->save();
        $staleExpiry = $session->refresh()->expires_at;

        $session->extendPlaybackLease();

        $this->assertGreaterThan(
            $staleExpiry,
            $session->refresh()->expires_at,
        );

        // Throttled: a lease that is still comfortably inside its TTL is
        // left untouched so segment requests do not write on every hit.
        $settledExpiry = $session->expires_at;
        $session->extendPlaybackLease();
        $this->assertTrue($session->refresh()->expires_at->equalTo($settledExpiry));
    }

    public function test_non_ready_sessions_never_extend_their_lease(): void
    {
        [$session, $sourcePath] = $this->makeTranscodeSession();
        File::put($sourcePath, 'unsupported source');

        $session->extendPlaybackLease();

        $this->assertNull($session->refresh()->expires_at);
        $this->assertSame(TranscodeSession::STATUS_PENDING, $session->status);
    }

    public function test_capacity_wait_window_covers_the_conversion_timeout(): void
    {
        [$session, $sourcePath] = $this->makeTranscodeSession();
        File::put($sourcePath, 'unsupported source');

        config([
            'odissey.transcode_timeout_seconds' => 21600,
            'odissey.transcode_capacity_retry_seconds' => 5,
        ]);
        $job = new TranscodeMediaToHls($session->getKey());
        $this->assertSame(5, $job->backoff);
        $this->assertSame(4322, $job->tries);
        $this->assertGreaterThan(
            $job->timeout,
            $job->tries * $job->backoff,
            'The capacity-wait window must outlast one full conversion.',
        );

        config(['odissey.transcode_capacity_retry_seconds' => 30]);
        $stretched = new TranscodeMediaToHls($session->getKey());
        $this->assertSame(30, $stretched->backoff);
        $this->assertSame(722, $stretched->tries);
    }

    public function test_job_stops_before_ffmpeg_when_the_transcode_cache_is_over_quota(): void
    {
        [$session, $sourcePath] = $this->makeTranscodeSession();
        File::put($sourcePath, 'unsupported source');
        config(['odissey.transcode_max_bytes' => 1]);
        $orphanId = (string) Str::ulid();
        $orphanDirectory = config('odissey.transcode_path').'/'.$orphanId;
        File::ensureDirectoryExists($orphanDirectory);
        File::put($orphanDirectory.'/segment-00000.ts', 'over quota');
        $runner = new class extends FfmpegRunner
        {
            public bool $wasCalled = false;

            public function run(
                array $arguments,
                int $timeoutSeconds,
                ?callable $shouldContinue = null,
            ): void {
                $this->wasCalled = true;
            }
        };

        (new TranscodeMediaToHls($session->getKey()))->handle(
            $runner,
            app(FfmpegArguments::class),
            app(TranscodeStorage::class),
            app(TranscodeConcurrencyGate::class),
        );

        $session->refresh();
        $this->assertFalse($runner->wasCalled);
        $this->assertSame(TranscodeSession::STATUS_FAILED, $session->status);
        $this->assertSame('cache_quota_exceeded', $session->error_code);
        $this->assertFalse(
            File::isDirectory(config('odissey.transcode_path').'/'.$session->getKey()),
        );
        $this->assertTrue(File::isDirectory($orphanDirectory));
    }

    public function test_empty_or_zero_duration_hls_is_never_marked_complete(): void
    {
        [$session] = $this->makeTranscodeSession();
        $storage = app(TranscodeStorage::class);
        $storage->prepare($session);
        $segment = $storage->segmentPath($session, 'segment-00000.ts');

        File::put(
            $storage->manifestPath($session),
            "#EXTM3U\n#EXTINF:0.000000,\nsegment-00000.ts\n",
        );
        File::put($segment, '');
        $this->assertFalse($storage->hasCompleteOutput($session));

        File::put(
            $storage->manifestPath($session),
            "#EXTM3U\n#EXTINF:4.0,\nsegment-00000.ts\n",
        );
        $this->assertFalse($storage->hasCompleteOutput($session));

        File::put($segment, 'playable segment bytes');
        $this->assertTrue($storage->hasCompleteOutput($session));
    }

    /**
     * @return array{TranscodeSession, string}
     */
    private function makeTranscodeSession(): array
    {
        $user = User::factory()->create(['is_active' => true]);
        $sourcePath = $this->temporaryPath.'/source.mkv';
        $item = MediaItem::query()->create([
            'user_id' => $user->getKey(),
            'title' => 'Transcode fixture',
            'source_type' => 'local',
            'source_locator' => $sourcePath,
            'mime_type' => 'video/x-matroska',
            'requires_transcode' => true,
        ]);
        $session = TranscodeSession::query()->create([
            'user_id' => $user->getKey(),
            'media_item_id' => $item->getKey(),
            'status' => TranscodeSession::STATUS_PENDING,
        ]);

        return [$session, $sourcePath];
    }
}
