<?php

namespace App\Console\Commands;

use App\Jobs\Iptv\SyncIptvProvider;
use App\Models\Iptv\IptvProvider;
use Illuminate\Console\Command;

class RefreshIptvCatalogs extends Command
{
    protected $signature = 'iptv:catalog:refresh
        {--provider= : Refresh one provider ID}
        {--recover-upgrade : Queue providers marked for upgrade recovery}';

    protected $description = 'Queue catalog refreshes for enabled IPTV providers';

    public function handle(): int
    {
        $query = IptvProvider::query()->where('enabled', true);
        if ($this->option('recover-upgrade')) {
            $query->where(
                'last_error_code',
                'provider_catalog_upgrade_required',
            );
        }
        if ($this->option('provider')) {
            $query->whereKey($this->option('provider'));
        }

        $ids = $query->pluck('id');
        foreach ($ids as $id) {
            IptvProvider::query()->whereKey($id)->update([
                'sync_status' => 'queued',
                'last_error_code' => null,
            ]);
            SyncIptvProvider::dispatch($id);
        }

        $this->info('Queued '.$ids->count().' IPTV catalog refresh(es).');

        return self::SUCCESS;
    }
}
