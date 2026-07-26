<?php

namespace App\Jobs\Media;

use App\Models\TranscodeSession;
use App\Services\Media\Exceptions\TranscodeQuotaExceeded;
use App\Services\Media\FfmpegArguments;
use App\Services\Media\FfmpegRunner;
use App\Services\Media\SourceMaterializer;
use App\Services\Media\TranscodeConcurrencyGate;
use App\Services\Media\TranscodeStorage;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Throwable;

class TranscodeMediaToHls implements ShouldQueue
{
    use Queueable;

    public int $backoff = 5;

    public int $timeout = 300;

    public int $tries = 60;

    public function __construct(public readonly string $sessionId)
    {
        $this->onQueue('high');
    }

    /**
     * @return list<object>
     */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping('transcode-session:'.$this->sessionId))
                ->dontRelease()
                ->expireAfter($this->timeout + 30),
        ];
    }

    public function handle(
        FfmpegRunner $runner,
        FfmpegArguments $arguments,
        TranscodeStorage $storage,
        TranscodeConcurrencyGate $concurrency,
    ): void {
        $session = TranscodeSession::query()
            ->with('mediaItem')
            ->find($this->sessionId);

        if ($session === null || $session->isAvailable()) {
            return;
        }

        $lease = $concurrency->acquire($this->timeout + 30);

        if ($lease === null) {
            $this->release(
                max(1, (int) config('odissey.transcode_capacity_retry_seconds', 5)),
            );

            return;
        }

        try {
            $session->update([
                'status' => TranscodeSession::STATUS_PROCESSING,
                'error_code' => null,
                'started_at' => now(),
                'finished_at' => null,
                'expires_at' => null,
            ]);

            $materialized = null;

            try {
                $materialized = app(SourceMaterializer::class)->materialize($session->mediaItem);
                $sourcePath = $materialized['path'];

                if (! File::isFile($sourcePath) || ! File::isReadable($sourcePath)) {
                    $this->markFailed($session, 'source_unavailable', $storage);

                    return;
                }

                $storage->prepare($session);
                $storage->assertWithinQuota();
                $runner->run(
                    $arguments->hls(
                        $sourcePath,
                        $storage->manifestPath($session),
                        $storage->segmentPattern($session),
                        $session->profile,
                        $session->audio_track,
                    ),
                    $this->timeout - 20,
                    fn (): bool => $storage->isWithinStorageLimits(),
                );
                $storage->assertWithinQuota();

                if (! $storage->hasCompleteOutput($session)) {
                    $this->markFailed($session, 'output_incomplete', $storage);

                    return;
                }

                $session->update([
                    'status' => TranscodeSession::STATUS_READY,
                    'manifest_relative_path' => 'index.m3u8',
                    'error_code' => null,
                    'finished_at' => now(),
                    'expires_at' => now()->addMinutes(
                        max(1, (int) config('odissey.transcode_ttl_minutes', 30)),
                    ),
                ]);
            } catch (TranscodeQuotaExceeded) {
                $this->markFailed($session, 'cache_quota_exceeded', $storage);
            } catch (ProcessTimedOutException) {
                $this->markFailed($session, 'transcode_timeout', $storage);
            } catch (ProcessFailedException) {
                $this->markFailed($session, 'transcode_failed', $storage);
            } catch (Throwable $exception) {
                Log::warning('Media transcode failed unexpectedly.', [
                    'session_id' => $session->getKey(),
                    'exception' => $exception::class,
                ]);
                $this->markFailed($session, 'transcode_internal', $storage);
            } finally {
                if (($materialized['temporary'] ?? false) === true) {
                    File::delete($materialized['path']);
                }
            }
        } finally {
            try {
                $lease->release();
            } catch (Throwable $exception) {
                Log::warning('Transcode concurrency lease release failed.', [
                    'session_id' => $session->getKey(),
                    'exception' => $exception::class,
                ]);
            }
        }
    }

    public function failed(?Throwable $exception): void
    {
        $session = TranscodeSession::query()->find($this->sessionId);

        if (
            $session !== null
            && in_array($session->status, [
                TranscodeSession::STATUS_PENDING,
                TranscodeSession::STATUS_PROCESSING,
            ], true)
        ) {
            $this->markFailed(
                $session,
                'transcode_capacity_timeout',
                app(TranscodeStorage::class),
            );
        }
    }

    private function markFailed(
        TranscodeSession $session,
        string $errorCode,
        TranscodeStorage $storage,
    ): void {
        try {
            $storage->delete($session);
        } catch (Throwable $exception) {
            Log::warning('Partial transcode cleanup failed.', [
                'session_id' => $session->getKey(),
                'exception' => $exception::class,
            ]);
        }

        $session->update([
            'status' => TranscodeSession::STATUS_FAILED,
            'manifest_relative_path' => null,
            'error_code' => $errorCode,
            'finished_at' => now(),
            'expires_at' => null,
        ]);
    }
}
