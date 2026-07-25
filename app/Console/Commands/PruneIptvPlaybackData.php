<?php

namespace App\Console\Commands;

use App\Models\Iptv\IptvPlaybackSession;
use Illuminate\Console\Command;

class PruneIptvPlaybackData extends Command
{
    protected $signature = 'iptv:prune';

    protected $description = 'Delete expired IPTV playback sessions and their resource tokens and attempts';

    public function handle(): int
    {
        $deleted = 0;

        IptvPlaybackSession::query()
            ->where('expires_at', '<', now())
            ->orderBy('expires_at')
            ->chunkById(250, function ($sessions) use (&$deleted): void {
                $ids = $sessions->pluck('id');
                $deleted += IptvPlaybackSession::query()->whereKey($ids)->delete();
            }, 'id');

        $this->info("Pruned {$deleted} expired IPTV playback session(s).");

        return self::SUCCESS;
    }
}
