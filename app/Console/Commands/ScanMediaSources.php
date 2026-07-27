<?php

namespace App\Console\Commands;

use App\Models\MediaSource;
use App\Services\Media\MediaScanDispatcher;
use Illuminate\Console\Command;

class ScanMediaSources extends Command
{
    protected $signature = 'media:sources:scan
        {--source= : Scan one source ULID}
        {--recover-interrupted : Requeue scans interrupted by a stopped container}';

    protected $description = 'Queue incremental scans for enabled media sources';

    public function handle(MediaScanDispatcher $dispatcher): int
    {
        if ($this->option('recover-interrupted')) {
            MediaSource::query()
                ->where('enabled', true)
                ->whereNot('type', MediaSource::TYPE_IPTV)
                ->whereIn('scan_status', ['queued', 'scanning'])
                ->update([
                    'scan_status' => 'failed',
                    'active_scan_token' => null,
                    'last_error_code' => 'source_scan_interrupted',
                ]);
        }

        $query = MediaSource::query()
            ->where('enabled', true)
            ->whereNotIn('scan_status', ['queued', 'scanning']);
        if ($this->option('recover-interrupted')) {
            $query->whereIn(
                'last_error_code',
                [
                    'source_scan_interrupted',
                    'source_scan_upgrade_required',
                ],
            );
        }
        if ($this->option('source')) {
            $query->whereKey($this->option('source'));
        }
        $ids = $query->pluck('id');
        $queued = 0;
        foreach ($ids as $id) {
            if ($dispatcher->queue($id)) {
                $queued++;
            }
        }
        $this->info('Queued '.$queued.' media source scan(s).');

        return self::SUCCESS;
    }
}
