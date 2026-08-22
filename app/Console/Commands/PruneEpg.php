<?php

namespace App\Console\Commands;

use App\Models\Iptv\EpgProgram;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class PruneEpg extends Command
{
    private const CHUNK_SIZE = 1000;

    protected $signature = 'iptv:epg:prune';

    protected $description = 'Remove expired programme-guide rows';

    public function handle(): int
    {
        $count = 0;

        // Batched deletes keep the single SQLite writer available to
        // interactive requests instead of holding one long exclusive pass
        // over potentially hundreds of thousands of guide rows.
        do {
            $ids = EpgProgram::query()
                ->where('ends_at', '<', now()->subDay())
                ->orderBy('id')
                ->limit(self::CHUNK_SIZE)
                ->pluck('id');

            if ($ids->isEmpty()) {
                break;
            }

            $count += DB::table('epg_programs')
                ->whereIn('id', $ids)
                ->delete();
        } while ($ids->count() === self::CHUNK_SIZE);

        $this->info("Pruned {$count} expired guide rows.");

        return self::SUCCESS;
    }
}
