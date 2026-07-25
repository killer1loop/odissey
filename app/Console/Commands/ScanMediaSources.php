<?php

namespace App\Console\Commands;

use App\Jobs\Media\ScanMediaSource;
use App\Models\MediaSource;
use Illuminate\Console\Command;

class ScanMediaSources extends Command
{
    protected $signature = 'media:sources:scan {--source= : Scan one source ULID}';

    protected $description = 'Queue incremental scans for enabled media sources';

    public function handle(): int
    {
        $query = MediaSource::query()->where('enabled', true);
        if ($this->option('source')) {
            $query->whereKey($this->option('source'));
        }
        $ids = $query->pluck('id');
        foreach ($ids as $id) {
            ScanMediaSource::dispatch($id);
        }
        $this->info('Queued '.$ids->count().' media source scan(s).');

        return self::SUCCESS;
    }
}
