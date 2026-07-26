<?php

namespace App\Services\Iptv;

final readonly class ChannelLogoResolution
{
    /**
     * @param  array<string, array{url: string, channel_id: string}>  $matches
     */
    public function __construct(
        public bool $available,
        private array $matches = [],
    ) {}

    /** @return array{url: string, channel_id: string}|null */
    public function match(string $externalId): ?array
    {
        return $this->matches[$externalId] ?? null;
    }
}
