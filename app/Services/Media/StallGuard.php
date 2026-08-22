<?php

namespace App\Services\Media;

/**
 * Time-based stall detection for streaming reads. A fixed count of empty
 * reads aborts healthy-but-slow upstreams; a resettable deadline tolerates
 * pauses up to the configured budget instead.
 */
final class StallGuard
{
    private int $deadlineNanoseconds;

    public function __construct(
        private readonly int $maximumSeconds,
    ) {
        $this->deadlineNanoseconds = $this->now()
            + $this->maximumSeconds * 1_000_000_000;
    }

    public static function fromConfig(string $key, int $defaultSeconds): self
    {
        return new self(max(
            1,
            (int) config($key, $defaultSeconds),
        ));
    }

    public function expired(): bool
    {
        return $this->now() >= $this->deadlineNanoseconds;
    }

    public function reset(): void
    {
        $this->deadlineNanoseconds = $this->now()
            + $this->maximumSeconds * 1_000_000_000;
    }

    private function now(): int
    {
        return hrtime(true);
    }
}
