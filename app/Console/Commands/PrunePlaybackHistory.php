<?php

namespace App\Console\Commands;

use App\Models\PlaybackHistory;
use Illuminate\Console\Command;

class PrunePlaybackHistory extends Command
{
    protected $description = 'Remove playback history older than the configured retention period';

    protected $signature = 'media:history:prune
                            {--dry-run : Count eligible rows without deleting them}';

    public function handle(): int
    {
        $days = min(
            3650,
            max(1, (int) config('odissey.playback_history_retention_days', 365)),
        );
        $query = PlaybackHistory::query()
            ->where('played_at', '<', now()->subDays($days));

        if ($this->option('dry-run')) {
            $this->info(sprintf(
                'Eligible %d playback history row(s).',
                $query->count(),
            ));

            return self::SUCCESS;
        }

        $deleted = 0;
        do {
            $ids = (clone $query)->limit(1000)->pluck('id');
            $batch = $ids->isEmpty()
                ? 0
                : PlaybackHistory::query()->whereKey($ids)->delete();
            $deleted += $batch;
        } while ($batch === 1000);

        $this->info(sprintf('Removed %d playback history row(s).', $deleted));

        return self::SUCCESS;
    }
}
