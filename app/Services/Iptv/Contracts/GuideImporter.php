<?php

namespace App\Services\Iptv\Contracts;

use App\Models\Iptv\IptvProvider;
use App\Services\Iptv\GuideImportResult;

interface GuideImporter
{
    /**
     * Import a bounded guide window and return the number of programs seen.
     *
     * A future full XMLTV importer implements this same boundary without
     * changing jobs, controllers, or the guide query surface.
     */
    public function import(
        IptvProvider $provider,
        int $channelLimit,
        int $afterChannelId = 0,
    ): GuideImportResult;
}
