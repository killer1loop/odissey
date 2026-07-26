<?php

namespace App\Console\Commands;

use App\Jobs\Iptv\SyncIptvGuide;
use App\Models\Iptv\IptvProvider;
use Illuminate\Console\Command;

class RefreshEpg extends Command
{
    protected $signature = 'iptv:epg:refresh';

    protected $description = 'Queue an EPG refresh for every enabled IPTV provider';

    public function handle(): int
    {
        $queued = 0;

        IptvProvider::query()
            ->select('id')
            ->where('enabled', true)
            ->orderBy('id')
            ->chunkById(250, function ($providers) use (&$queued): void {
                foreach ($providers as $provider) {
                    SyncIptvGuide::dispatch($provider->id);
                    $queued++;
                }
            });

        $this->info("Queued EPG refresh for {$queued} enabled provider(s).");

        return self::SUCCESS;
    }
}
