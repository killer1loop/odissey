<?php

namespace App\Services\Iptv;

use App\Models\Iptv\IptvProvider;
use App\Models\MediaSource;
use Illuminate\Support\Facades\DB;

class IptvVodImportProgress
{
    public function complete(
        int $providerId,
        string $sourceId,
        string $importToken,
        bool $failed,
    ): void {
        DB::transaction(function () use (
            $providerId,
            $sourceId,
            $importToken,
            $failed,
        ): void {
            $source = MediaSource::query()
                ->whereKey($sourceId)
                ->where('iptv_provider_id', $providerId)
                ->where('active_scan_token', $importToken)
                ->lockForUpdate()
                ->first();
            if ($source === null) {
                return;
            }

            $source->scan_processed++;
            if ($failed) {
                $source->scan_failed++;
            }

            if ($source->scan_processed >= $source->scan_discovered) {
                $error = $source->scan_failed > 0
                    ? 'provider_vod_partial_failure'
                    : null;
                $source->forceFill([
                    'scan_status' => 'ready',
                    'active_scan_token' => null,
                    'last_error_code' => $error,
                    'last_scanned_at' => now(),
                ]);
                IptvProvider::query()
                    ->whereKey($providerId)
                    ->update([
                        'sync_status' => 'ready',
                        'last_error_code' => $error,
                        'last_synced_at' => now(),
                    ]);
            }

            $source->save();
        }, 3);
    }
}
