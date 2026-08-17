<?php

namespace Tests\Feature\Media;

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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class RemoteTranscodeEndToEndTest extends TestCase
{
    use RefreshDatabase;

    private string $temporaryPath;

    protected function setUp(): void
    {
        parent::setUp();

        if (! config('app.key')) {
            config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        }

        $this->temporaryPath = sys_get_temp_dir()
            .'/odissey-remote-transcode-e2e-'.Str::uuid();
        File::ensureDirectoryExists($this->temporaryPath);
        config([
            'odissey.ffmpeg_threads' => 1,
            'odissey.remote_transcode_max_source_bytes' => 64 * 1024 * 1024,
            'odissey.transcode_max_bytes' => 64 * 1024 * 1024,
            'odissey.transcode_min_free_bytes' => 0,
            'odissey.transcode_path' => $this->temporaryPath.'/transcodes',
        ]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->temporaryPath);

        parent::tearDown();
    }

    public function test_seek_dependent_remote_movie_produces_playable_hls(): void
    {
        $ffmpeg = (string) config('odissey.ffmpeg_binary', 'ffmpeg');
        $ffprobe = (string) config('odissey.ffprobe_binary', 'ffprobe');
        if (! $this->commandIsAvailable($ffmpeg)) {
            $this->markTestSkipped('FFmpeg is required for the media E2E test.');
        }
        if (! $this->commandIsAvailable($ffprobe)) {
            $this->markTestSkipped('FFprobe is required for the media E2E test.');
        }

        $sourcePath = $this->temporaryPath.'/moov-at-end.mp4';
        (new Process([
            $ffmpeg,
            '-hide_banner',
            '-loglevel',
            'error',
            '-nostdin',
            '-y',
            '-f',
            'lavfi',
            '-i',
            'testsrc2=size=320x180:rate=25',
            '-f',
            'lavfi',
            '-i',
            'sine=frequency=550:sample_rate=48000',
            '-t',
            '2',
            '-shortest',
            '-c:v',
            'libx264',
            '-preset',
            'ultrafast',
            '-pix_fmt',
            'yuv420p',
            '-c:a',
            'aac',
            '-b:a',
            '96k',
            $sourcePath,
        ], timeout: 60))->mustRun();

        $sourceBytes = File::get($sourcePath);
        $mdat = strpos($sourceBytes, 'mdat');
        $moov = strpos($sourceBytes, 'moov');
        $this->assertIsInt($mdat);
        $this->assertIsInt($moov);
        $this->assertGreaterThan(
            $mdat,
            $moov,
            'The fixture must keep its seek metadata after the media payload.',
        );

        $user = User::factory()->create(['is_active' => true]);
        $source = MediaSource::query()->create([
            'name' => 'Remote seek E2E',
            'type' => MediaSource::TYPE_WEBDAV,
            'configuration' => ['url' => 'https://media.example.test'],
            'capabilities' => [
                'range' => true,
                'seekable' => true,
                'read_only' => true,
            ],
            'enabled' => true,
        ]);
        $item = MediaItem::query()->create([
            'user_id' => $user->getKey(),
            'media_source_id' => $source->getKey(),
            'stable_id' => hash('sha256', 'remote-seek-e2e'),
            'title' => 'Remote seek E2E',
            'relative_path' => 'moov-at-end.mp4',
            'source_type' => MediaSource::TYPE_WEBDAV,
            'source_locator' => 'moov-at-end.mp4',
            'mime_type' => 'video/mp4',
            'container' => 'mp4',
            'video_codec' => 'h264',
            'audio_codec' => 'aac',
            'size_bytes' => File::size($sourcePath),
            'requires_transcode' => true,
        ]);
        $session = TranscodeSession::query()->create([
            'user_id' => $user->getKey(),
            'media_item_id' => $item->getKey(),
            'status' => TranscodeSession::STATUS_PENDING,
        ]);
        $adapter = new class($sourcePath) implements MediaSourceAdapter
        {
            public function __construct(private readonly string $sourcePath) {}

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
                return new SourceResponse(
                    Utils::streamFor(fopen($this->sourcePath, 'rb')),
                    200,
                    filesize($this->sourcePath),
                    'video/mp4',
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

        $storage = app(TranscodeStorage::class);
        $runner = new class($storage, $session) extends FfmpegRunner
        {
            public ?string $materializedSource = null;

            public int $materializedBytes = 0;

            public int $quotaBytesDuringRun = 0;

            public bool $observedWhileFfmpegWasRunning = false;

            public bool $usedStdin = false;

            public function __construct(
                private readonly TranscodeStorage $storage,
                private readonly TranscodeSession $session,
            ) {
                parent::__construct();
            }

            public function run(
                array $arguments,
                int $timeoutSeconds,
                ?callable $shouldContinue = null,
            ): void {
                $inputIndex = array_search('-i', $arguments, true);
                $this->materializedSource = $arguments[$inputIndex + 1];
                $this->recordMaterializedSource();

                parent::run(
                    $arguments,
                    $timeoutSeconds,
                    function () use ($shouldContinue): bool {
                        $this->recordMaterializedSource();
                        $this->observedWhileFfmpegWasRunning = true;

                        return $shouldContinue?->__invoke() ?? true;
                    },
                );
            }

            public function runWithInput(
                array $arguments,
                int $timeoutSeconds,
                mixed $input,
                ?callable $shouldContinue = null,
            ): void {
                $this->usedStdin = true;

                parent::runWithInput(
                    $arguments,
                    $timeoutSeconds,
                    $input,
                    $shouldContinue,
                );
            }

            private function recordMaterializedSource(): void
            {
                if (
                    $this->materializedSource === null
                    || ! File::isFile($this->materializedSource)
                ) {
                    return;
                }

                clearstatcache(true, $this->materializedSource);
                $this->materializedBytes = File::size(
                    $this->materializedSource,
                );
                $this->quotaBytesDuringRun = $this->storage->bytesUsed();
            }
        };

        (new TranscodeMediaToHls($session->getKey()))->handle(
            $runner,
            app(FfmpegArguments::class),
            $storage,
            app(TranscodeConcurrencyGate::class),
            $registry,
        );

        $session->refresh();
        $segmentPath = $storage->segmentPath($session, 'segment-00000.ts');
        $this->assertSame(TranscodeSession::STATUS_READY, $session->status);
        $this->assertNull($session->error_code);
        $this->assertTrue($storage->hasCompleteOutput($session));
        $this->assertGreaterThan(0, File::size($segmentPath));
        $this->assertFalse($runner->usedStdin);
        $this->assertTrue($runner->observedWhileFfmpegWasRunning);
        $this->assertSame(
            dirname($storage->manifestPath($session)).'/source.mp4',
            $runner->materializedSource,
        );
        $this->assertSame(File::size($sourcePath), $runner->materializedBytes);
        $this->assertGreaterThanOrEqual(
            $runner->materializedBytes,
            $runner->quotaBytesDuringRun,
        );
        $this->assertLessThanOrEqual(
            (int) config('odissey.transcode_max_bytes'),
            $runner->quotaBytesDuringRun,
        );
        $this->assertFileDoesNotExist((string) $runner->materializedSource);

        $probe = new Process([
            $ffprobe,
            '-v',
            'error',
            '-show_entries',
            'format=duration',
            '-of',
            'json',
            $storage->manifestPath($session),
        ], timeout: 30);
        $probe->mustRun();
        $result = json_decode($probe->getOutput(), true, 8, JSON_THROW_ON_ERROR);
        $this->assertGreaterThan(0, (float) $result['format']['duration']);
    }

    public function test_sequential_remote_mkv_streams_directly_to_playable_hls(): void
    {
        $ffmpeg = (string) config('odissey.ffmpeg_binary', 'ffmpeg');
        $ffprobe = (string) config('odissey.ffprobe_binary', 'ffprobe');
        if (! $this->commandIsAvailable($ffmpeg)) {
            $this->markTestSkipped('FFmpeg is required for the media E2E test.');
        }
        if (! $this->commandIsAvailable($ffprobe)) {
            $this->markTestSkipped('FFprobe is required for the media E2E test.');
        }

        $sourcePath = $this->temporaryPath.'/sequential.mkv';
        (new Process([
            $ffmpeg,
            '-hide_banner',
            '-loglevel',
            'error',
            '-nostdin',
            '-y',
            '-f',
            'lavfi',
            '-i',
            'testsrc2=size=320x180:rate=25',
            '-f',
            'lavfi',
            '-i',
            'sine=frequency=660:sample_rate=48000',
            '-t',
            '2',
            '-shortest',
            '-c:v',
            'libx264',
            '-preset',
            'ultrafast',
            '-pix_fmt',
            'yuv420p',
            '-c:a',
            'aac',
            '-b:a',
            '96k',
            $sourcePath,
        ], timeout: 60))->mustRun();

        $user = User::factory()->create(['is_active' => true]);
        $source = MediaSource::query()->create([
            'name' => 'Remote sequential E2E',
            'type' => MediaSource::TYPE_S3,
            'configuration' => ['endpoint' => 'https://media.example.test'],
            'capabilities' => [
                'range' => true,
                'seekable' => true,
                'read_only' => true,
            ],
            'enabled' => true,
        ]);
        $item = MediaItem::query()->create([
            'user_id' => $user->getKey(),
            'media_source_id' => $source->getKey(),
            'stable_id' => hash('sha256', 'remote-sequential-e2e'),
            'title' => 'Remote sequential E2E',
            'relative_path' => 'sequential.mkv',
            'source_type' => MediaSource::TYPE_S3,
            'source_locator' => 'sequential.mkv',
            'mime_type' => 'video/x-matroska',
            'container' => 'mkv',
            'video_codec' => 'h264',
            'audio_codec' => 'aac',
            'size_bytes' => File::size($sourcePath),
            'requires_transcode' => true,
        ]);
        $session = TranscodeSession::query()->create([
            'user_id' => $user->getKey(),
            'media_item_id' => $item->getKey(),
            'status' => TranscodeSession::STATUS_PENDING,
        ]);
        $adapter = new class($sourcePath) implements MediaSourceAdapter
        {
            public function __construct(private readonly string $sourcePath) {}

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
                return new SourceResponse(
                    Utils::streamFor(fopen($this->sourcePath, 'rb')),
                    200,
                    filesize($this->sourcePath),
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
        $storage = app(TranscodeStorage::class);
        $runner = new class($storage, $session) extends FfmpegRunner
        {
            public bool $usedStdin = false;

            public ?string $sourceArgument = null;

            /** @var list<string> */
            public array $snapshotsDuringRun = [];

            public function __construct(
                private readonly TranscodeStorage $storage,
                private readonly TranscodeSession $session,
            ) {
                parent::__construct();
            }

            public function runWithInput(
                array $arguments,
                int $timeoutSeconds,
                mixed $input,
                ?callable $shouldContinue = null,
            ): void {
                $this->usedStdin = true;
                $inputIndex = array_search('-i', $arguments, true);
                $this->sourceArgument = $arguments[$inputIndex + 1];
                $this->snapshotsDuringRun = File::glob(
                    dirname($this->storage->manifestPath($this->session))
                        .'/source.*',
                );

                parent::runWithInput(
                    $arguments,
                    $timeoutSeconds,
                    $input,
                    $shouldContinue,
                );
            }
        };

        (new TranscodeMediaToHls($session->getKey()))->handle(
            $runner,
            app(FfmpegArguments::class),
            $storage,
            app(TranscodeConcurrencyGate::class),
            $registry,
        );

        $session->refresh();
        $segmentPath = $storage->segmentPath($session, 'segment-00000.ts');
        $this->assertTrue($runner->usedStdin);
        $this->assertSame('pipe:0', $runner->sourceArgument);
        $this->assertSame([], $runner->snapshotsDuringRun);
        $this->assertSame(TranscodeSession::STATUS_READY, $session->status);
        $this->assertNull($session->error_code);
        $this->assertTrue($storage->hasCompleteOutput($session));
        $this->assertGreaterThan(0, File::size($segmentPath));

        $probe = new Process([
            $ffprobe,
            '-v',
            'error',
            '-show_entries',
            'format=duration',
            '-of',
            'json',
            $storage->manifestPath($session),
        ], timeout: 30);
        $probe->mustRun();
        $result = json_decode($probe->getOutput(), true, 8, JSON_THROW_ON_ERROR);
        $this->assertGreaterThan(0, (float) $result['format']['duration']);
    }

    private function commandIsAvailable(string $command): bool
    {
        $process = new Process([$command, '-version'], timeout: 10);
        $process->run();

        return $process->isSuccessful();
    }
}
