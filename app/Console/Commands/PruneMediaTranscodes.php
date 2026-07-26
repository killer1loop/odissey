<?php

namespace App\Console\Commands;

use App\Services\Media\TranscodePruner;
use Illuminate\Console\Command;

class PruneMediaTranscodes extends Command
{
    protected $description = 'Remove expired, failed, abandoned, and orphaned transient HLS output';

    protected $signature = 'media:transcodes:prune
                            {--dry-run : Report eligible transient output without deleting it}';

    public function handle(TranscodePruner $pruner): int
    {
        $result = $pruner->prune((bool) $this->option('dry-run'));
        $verb = $this->option('dry-run') ? 'Eligible' : 'Removed';

        $this->info(sprintf(
            '%s %d transcode session(s), %d orphan directory/directories, %d transient file(s), and %d byte(s).',
            $verb,
            $result['sessions'],
            $result['orphan_directories'],
            $result['transient_files'],
            $result['bytes'],
        ));

        return self::SUCCESS;
    }
}
