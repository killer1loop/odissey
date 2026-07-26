<?php

namespace App\Services\Iptv;

final class IptvImportMemoryBudget
{
    public function apply(): void
    {
        ini_set('memory_limit', $this->megabytes().'M');
    }

    public function megabytes(): int
    {
        return min(
            1024,
            max(
                256,
                (int) config('iptv.import_memory_limit_mb', 768),
            ),
        );
    }
}
