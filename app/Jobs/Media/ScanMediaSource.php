<?php

namespace App\Jobs\Media;

use App\Models\MediaSource;
use App\Services\Media\MediaScanProgress;
use App\Services\Media\Sources\MediaSourceRegistry;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Throwable;

class ScanMediaSource implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    public int $timeout = 3600;

    public int $tries = 3;

    public int $uniqueFor = 3700;

    public readonly string $scanToken;

    public function __construct(
        public readonly string $sourceId,
        ?string $scanToken = null,
    ) {
        $this->scanToken = $scanToken ?? (string) Str::ulid();
        $this->onQueue('media-discovery');
    }

    public function uniqueId(): string
    {
        return $this->sourceId.':'.$this->scanToken;
    }

    public function middleware(): array
    {
        return [
            (new WithoutOverlapping('media-source:'.$this->sourceId))
                ->expireAfter(3700),
        ];
    }

    public function handle(
        MediaSourceRegistry $registry,
        MediaScanProgress $progress,
    ): void {
        if (! isset($this->scanToken)) {
            return;
        }

        $source = MediaSource::query()
            ->whereKey($this->sourceId)
            ->where('active_scan_token', $this->scanToken)
            ->first();
        if (
            $source === null
            || ! $source->enabled
            || $source->type === MediaSource::TYPE_IPTV
        ) {
            return;
        }

        $scanToken = $this->scanToken;
        $source->forceFill([
            'scan_status' => 'scanning',
            'scan_discovery_complete' => false,
            'scan_discovered' => 0,
            'scan_processed' => 0,
            'scan_failed' => 0,
            'scan_caption_jobs' => 0,
            'scan_probe_jobs' => 0,
            'last_error_code' => null,
        ])->save();
        $adapter = $registry->for($source);
        $objectCount = 0;
        $maximumObjects = min(
            500000,
            max(1, (int) config(
                'odissey.source_catalog_max_items',
                100000,
            )),
        );
        $extensions = array_merge(
            config('odissey.video_extensions'),
            config('odissey.audio_extensions'),
        );
        $jobs = [];

        try {
            $source->update([
                'capabilities' => $adapter->capabilities($source),
            ]);

            foreach ($adapter->objects($source) as $object) {
                if (++$objectCount > $maximumObjects) {
                    throw new \RuntimeException(
                        'source_catalog_item_limit',
                    );
                }
                if (! in_array(
                    strtolower(pathinfo(
                        $object->path,
                        PATHINFO_EXTENSION,
                    )),
                    $extensions,
                    true,
                )) {
                    continue;
                }

                $jobs[] = new ProcessMediaSourceObject(
                    sourceId: $source->id,
                    scanToken: $scanToken,
                    locator: $object->locator,
                    path: $object->path,
                    size: $object->size,
                    etag: $object->etag,
                    modifiedAt: $object->modifiedAt,
                );

                if (count($jobs) === 100) {
                    $this->dispatchJobs($source, $scanToken, $jobs);
                    $jobs = [];
                }
            }

            if ($jobs !== []) {
                $this->dispatchJobs($source, $scanToken, $jobs);
            }

            $progress->completeDiscovery($source->id, $scanToken);
        } catch (Throwable $exception) {
            Log::warning('Media source discovery failed.', [
                'source_id' => $source->id,
                'exception' => $exception::class,
            ]);
            MediaSource::query()
                ->whereKey($source->id)
                ->where('active_scan_token', $scanToken)
                ->update([
                    'scan_status' => 'failed',
                    'active_scan_token' => null,
                    'last_error_code' => 'source_scan_failed',
                ]);
        }
    }

    /**
     * @param  array<int, ProcessMediaSourceObject>  $jobs
     */
    private function dispatchJobs(
        MediaSource $source,
        string $scanToken,
        array $jobs,
    ): void {
        MediaSource::query()
            ->whereKey($source->id)
            ->where('active_scan_token', $scanToken)
            ->increment('scan_discovered', count($jobs));

        Queue::bulk($jobs, '', 'media-scan');
    }

    public function failed(?Throwable $exception): void
    {
        if (! isset($this->scanToken)) {
            return;
        }

        MediaSource::query()
            ->whereKey($this->sourceId)
            ->where('active_scan_token', $this->scanToken)
            ->update([
                'scan_status' => 'failed',
                'active_scan_token' => null,
                'last_error_code' => 'source_scan_failed',
            ]);
    }
}
