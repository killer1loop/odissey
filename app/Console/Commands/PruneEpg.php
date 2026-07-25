<?php

namespace App\Console\Commands;

use App\Models\Iptv\EpgProgram;
use Illuminate\Console\Command;

class PruneEpg extends Command
{
    protected $signature = 'iptv:epg:prune';

    protected $description = 'Remove expired programme-guide rows';

    public function handle(): int
    {
        $count = EpgProgram::query()->where('ends_at', '<', now()->subDay())->delete();
        $this->info("Pruned {$count} expired guide rows.");

        return self::SUCCESS;
    }
}
