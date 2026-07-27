<?php

namespace App\Services\Media;

use App\Jobs\Media\ScanMediaSource;
use App\Models\MediaSource;
use Illuminate\Support\Facades\DB;

class MediaScanDispatcher
{
    public function queue(MediaSource|string $source): bool
    {
        return DB::transaction(function () use ($source): bool {
            $sourceId = $source instanceof MediaSource
                ? $source->getKey()
                : $source;
            $locked = MediaSource::query()
                ->whereKey($sourceId)
                ->lockForUpdate()
                ->first();
            if (
                $locked === null
                || ! $locked->enabled
                || $locked->type === MediaSource::TYPE_IPTV
                || in_array(
                    $locked->scan_status,
                    ['queued', 'scanning'],
                    true,
                )
            ) {
                return false;
            }

            $job = new ScanMediaSource($locked->getKey());
            $locked->forceFill([
                'scan_status' => 'queued',
                'active_scan_token' => $job->scanToken,
                'scan_discovery_complete' => false,
                'scan_discovered' => 0,
                'scan_processed' => 0,
                'scan_failed' => 0,
                'scan_caption_jobs' => 0,
                'last_error_code' => null,
            ])->save();

            dispatch($job)->afterCommit();

            return true;
        }, 3);
    }
}
