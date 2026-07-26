<?php

namespace App\Console\Commands;

use App\Services\Iptv\ChannelLogoResolver;
use App\Services\Iptv\IptvImportMemoryBudget;
use Illuminate\Console\Command;
use Throwable;

class RefreshChannelLogos extends Command
{
    protected $signature = 'iptv:logos:refresh';

    protected $description = 'Refresh channel logos from the external IPTV-org catalog';

    public function handle(
        ChannelLogoResolver $logos,
        IptvImportMemoryBudget $memory,
    ): int {
        $memory->apply();

        try {
            $result = $logos->refreshExistingChannels();
        } catch (Throwable) {
            $this->error('The external channel logo catalog was unavailable.');

            return self::FAILURE;
        }

        $this->info(
            "Matched {$result['matched']} channel logo(s); "
            ."{$result['unmatched']} channel(s) use initials.",
        );

        return self::SUCCESS;
    }
}
