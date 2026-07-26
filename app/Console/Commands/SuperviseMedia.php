<?php

namespace App\Console\Commands;

use App\Services\Media\TranscodePruner;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class SuperviseMedia extends Command
{
    protected $signature = 'media:supervise {--once : Run one supervision cycle and exit}';

    protected $description = 'Maintain transcode leases, cleanup and a runtime heartbeat';

    private bool $running = true;

    public function handle(TranscodePruner $pruner): int
    {
        if (function_exists('pcntl_async_signals')) {
            pcntl_async_signals(true);
            pcntl_signal(SIGTERM, fn () => $this->running = false);
            pcntl_signal(SIGINT, fn () => $this->running = false);
        }

        do {
            Cache::store(
                (string) config('odissey.runtime_cache_store', 'file'),
            )->put(
                'odissey:media-supervisor-heartbeat',
                now()->toIso8601String(),
                120,
            );
            $pruner->prune();

            if ($this->option('once')) {
                break;
            }

            for ($i = 0; $i < 60 && $this->running; $i++) {
                sleep(1);
            }
        } while ($this->running);

        return self::SUCCESS;
    }
}
