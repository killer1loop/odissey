<?php

namespace App\Console\Commands;

use App\Models\Iptv\IptvProvider;
use App\Services\Iptv\IptvImportMemoryBudget;
use App\Services\Iptv\XtreamVodArtworkSynchronizer;
use Illuminate\Console\Command;
use Throwable;

class RefreshIptvArtwork extends Command
{
    protected $signature = 'iptv:artwork:refresh
        {--provider= : Refresh one provider ID}';

    protected $description = 'Import trusted movie and series artwork URLs';

    public function handle(
        XtreamVodArtworkSynchronizer $artwork,
        IptvImportMemoryBudget $memory,
    ): int {
        $memory->apply();

        $query = IptvProvider::query()->where('enabled', true);
        if ($this->option('provider')) {
            $query->whereKey($this->option('provider'));
        }

        $failed = false;
        foreach ($query->get() as $provider) {
            if (($provider->config['api'] ?? 'xtream') === 'm3u') {
                continue;
            }

            try {
                $result = $artwork->sync($provider);
                $this->info(sprintf(
                    '%s: %d movies, %d series, %d records updated.',
                    $provider->name,
                    $result['movies'],
                    $result['series'],
                    $result['updated'],
                ));
            } catch (Throwable) {
                $failed = true;
                $this->error($provider->name.': artwork refresh failed.');
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
