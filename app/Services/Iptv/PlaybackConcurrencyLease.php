<?php

namespace App\Services\Iptv;

use Illuminate\Contracts\Cache\Lock;
use Throwable;

class PlaybackConcurrencyLease
{
    private bool $released = false;

    private int $deadlineNanoseconds;

    public function __construct(
        private readonly Lock $sessionLock,
        private readonly Lock $providerLock,
        int $leaseSeconds,
        ?int $startedAtNanoseconds = null,
    ) {
        $this->deadlineNanoseconds = (
            $startedAtNanoseconds ?? hrtime(true)
        ) + ($leaseSeconds * 1_000_000_000);
    }

    public function capLifetime(int $seconds): void
    {
        $this->deadlineNanoseconds = min(
            $this->deadlineNanoseconds,
            hrtime(true) + (max(0, $seconds) * 1_000_000_000),
        );
    }

    public function expired(): bool
    {
        return $this->remainingNanoseconds() <= 0;
    }

    public function remainingNanoseconds(): int
    {
        return max(0, $this->deadlineNanoseconds - hrtime(true));
    }

    public function release(): void
    {
        if ($this->released) {
            return;
        }

        $this->released = true;

        foreach ([$this->providerLock, $this->sessionLock] as $lock) {
            try {
                $lock->release();
            } catch (Throwable) {
                // The cache TTL remains a bounded fallback if release fails.
            }
        }
    }

    public function __destruct()
    {
        $this->release();
    }
}
