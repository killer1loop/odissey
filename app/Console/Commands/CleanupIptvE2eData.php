<?php

namespace App\Console\Commands;

use App\Models\Iptv\IptvProvider;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class CleanupIptvE2eData extends Command
{
    protected $signature = 'iptv:e2e:clean
        {--provider= : Exact provider name; without it, only providers explicitly tagged e2e are selected}
        {--force : Required in production and skips interactive confirmation}';

    protected $description = 'Explicitly remove provider, catalog, favorite, and playback data created by IPTV E2E tests';

    public function handle(): int
    {
        $providerName = trim((string) $this->option('provider'));
        $providers = IptvProvider::query()
            ->when(
                $providerName !== '',
                fn ($query) => $query->where('name', $providerName),
            )
            ->get()
            ->when(
                $providerName === '',
                fn ($items) => $items->filter(
                    fn (IptvProvider $provider) => (
                        ($provider->config['e2e'] ?? false) === true
                        || str_starts_with($provider->name, 'E2E ')
                        || str_ends_with($provider->name, ' E2E')
                        || str_ends_with($provider->name, ' [E2E]')
                    ),
                ),
            );

        if ($providers->isEmpty()) {
            $this->warn('No matching E2E-tagged provider was found. No data was changed.');

            return self::SUCCESS;
        }

        if (app()->isProduction() && ! $this->option('force')) {
            $this->error('--force is required to clean E2E data in production.');

            return self::FAILURE;
        }

        if (
            ! $this->option('force')
            && ! $this->confirm('Delete the selected IPTV E2E data?')
        ) {
            $this->info('Cleanup cancelled.');

            return self::SUCCESS;
        }

        $providerIds = $providers->pluck('id');
        $providerCount = DB::transaction(function () use ($providerIds): int {
            return IptvProvider::query()->whereKey($providerIds)->delete();
        });

        $this->info(sprintf(
            'IPTV E2E cleanup complete: %d provider(s) and their catalog/playback state.',
            $providerCount,
        ));

        return self::SUCCESS;
    }
}
