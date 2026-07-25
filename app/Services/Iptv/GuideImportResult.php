<?php

namespace App\Services\Iptv;

class GuideImportResult
{
    public function __construct(
        public readonly int $programsImported,
        public readonly int $lastChannelId,
        public readonly bool $hasMore,
    ) {}
}
