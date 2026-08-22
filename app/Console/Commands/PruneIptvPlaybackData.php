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
        $retentionHours = min(
            168,
            max(
                1,
                (int) config(
                    'iptv.playback_diagnostics_retention_hours',
                    24,
                ),
            ),
        );
        $cutoff = now()->subHours($retentionHours);

        // chunkById already orders by id; an additional orderBy corrupts the
        // id cursor and silently skips rows in later chunks.
        IptvPlaybackSession::query()
            ->where('expires_at', '<', $cutoff)
            ->chunkById(250, function ($sessions) use (&$deleted): void {
                $ids = $sessions->pluck('id');
                $deleted += IptvPlaybackSession::query()->whereKey($ids)->delete();
            }, 'id');

        $this->info("Pruned {$deleted} expired IPTV playback session(s).");

        return self::SUCCESS;
    }
}
