<?php

namespace App\Jobs\Media;

use App\Models\MediaItem;
use App\Models\MediaSource;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

class FinalizeMediaSourceScan implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $timeout = 300;

    public int $tries = 3;

    public int $uniqueFor = 3600;

    public function __construct(
        public readonly string $sourceId,
        public readonly string $scanToken,
    ) {
        $this->onQueue('media-scan');
    }

    public function uniqueId(): string
    {
        return $this->sourceId.':'.$this->scanToken;
    }

    public function handle(): void
    {
        DB::transaction(function (): void {
            $source = MediaSource::query()
                ->whereKey($this->sourceId)
                ->where('active_scan_token', $this->scanToken)
                ->lockForUpdate()
                ->first();
            if (
                $source === null
                || ! $source->scan_discovery_complete
                || $source->scan_processed < $source->scan_discovered
            ) {
                return;
            }

            if ($source->scan_failed === 0) {
                MediaItem::query()
                    ->whereBelongsTo($source, 'source')
                    ->where(function ($query): void {
                        $query->whereNull('scan_token')
                            ->orWhere('scan_token', '!=', $this->scanToken);
                    })
                    ->update(['missing_at' => now()]);
            }

            $source->forceFill([
                'scan_status' => 'ready',
                'active_scan_token' => null,
                'last_error_code' => $source->scan_failed > 0
                    ? 'source_scan_partial_failure'
                    : null,
                'last_scanned_at' => now(),
            ])->save();
        });
    }
}
