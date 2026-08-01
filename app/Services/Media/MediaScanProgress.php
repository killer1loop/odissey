<?php

namespace App\Services\Media;

use App\Jobs\Media\FinalizeMediaSourceScan;
use App\Models\MediaSource;
use Illuminate\Support\Facades\DB;

class MediaScanProgress
{
    public function completeObject(
        string $sourceId,
        string $scanToken,
        bool $failed,
    ): void {
        $shouldFinalize = DB::transaction(function () use (
            $sourceId,
            $scanToken,
            $failed,
        ): bool {
            $source = MediaSource::query()
                ->whereKey($sourceId)
                ->where('active_scan_token', $scanToken)
                ->lockForUpdate()
                ->first();
            if ($source === null) {
                return false;
            }

            $source->scan_processed++;
            if ($failed) {
                $source->scan_failed++;
            }
            $source->save();

            return $source->scan_discovery_complete
                && $source->scan_processed >= $source->scan_discovered;
        });

        if ($shouldFinalize) {
            FinalizeMediaSourceScan::dispatch($sourceId, $scanToken);
        }
    }

    public function completeDiscovery(
        string $sourceId,
        string $scanToken,
    ): void {
        $shouldFinalize = DB::transaction(function () use (
            $sourceId,
            $scanToken,
        ): bool {
            $source = MediaSource::query()
                ->whereKey($sourceId)
                ->where('active_scan_token', $scanToken)
                ->lockForUpdate()
                ->first();
            if ($source === null) {
                return false;
            }

            $source->scan_discovery_complete = true;
            $source->save();

            return $source->scan_processed >= $source->scan_discovered;
        });

        if ($shouldFinalize) {
            FinalizeMediaSourceScan::dispatch($sourceId, $scanToken);
        }
    }

    public function reserveCaptionJob(
        string $sourceId,
        string $scanToken,
        int $maximum,
    ): bool {
        if ($maximum < 1) {
            return false;
        }

        return DB::transaction(function () use (
            $sourceId,
            $scanToken,
            $maximum,
        ): bool {
            $source = MediaSource::query()
                ->whereKey($sourceId)
                ->where('active_scan_token', $scanToken)
                ->lockForUpdate()
                ->first();
            if (
                $source === null
                || $source->scan_caption_jobs >= $maximum
            ) {
                return false;
            }

            $source->scan_caption_jobs++;
            $source->save();

            return true;
        });
    }

    public function reserveProbeJob(
        string $sourceId,
        string $scanToken,
        int $maximum,
    ): bool {
        if ($maximum < 1) {
            return false;
        }

        return DB::transaction(function () use (
            $sourceId,
            $scanToken,
            $maximum,
        ): bool {
            $source = MediaSource::query()
                ->whereKey($sourceId)
                ->where('active_scan_token', $scanToken)
                ->lockForUpdate()
                ->first();
            if (
                $source === null
                || $source->scan_probe_jobs >= $maximum
            ) {
                return false;
            }

            $source->scan_probe_jobs++;
            $source->save();

            return true;
        });
    }
}
