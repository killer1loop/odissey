<?php

namespace Tests\Unit\Media;

use App\Jobs\Media\TranscodeMediaToHls;
use App\Models\MediaItem;
use App\Models\TranscodeSession;
use App\Models\User;
use App\Services\Media\FfmpegArguments;
use App\Services\Media\FfmpegRunner;
use App\Services\Media\TranscodeConcurrencyGate;
use App\Services\Media\TranscodeStorage;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
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

                File::put($manifest, "#EXTM3U\nsegment-00000.ts\n");
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
