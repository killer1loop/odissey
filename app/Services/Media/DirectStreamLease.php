<?php

namespace App\Services\Media;

use Illuminate\Contracts\Cache\Lock;
use Throwable;

final class DirectStreamLease
{
    private bool $released = false;

    private readonly int $deadlineNanoseconds;

    /**
     * @param  list<Lock>  $locks
     */
    public function __construct(
        private readonly array $locks,
        int $maximumLifetimeSeconds,
        ?int $startedAtNanoseconds = null,
    ) {
        $this->deadlineNanoseconds = (
            $startedAtNanoseconds ?? hrtime(true)
        ) + (max(1, $maximumLifetimeSeconds) * 1_000_000_000);
    }

    public function expired(): bool
    {
        return $this->released || $this->remainingNanoseconds() <= 0;
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

        foreach (array_reverse($this->locks) as $lock) {
            try {
                $lock->release();
            } catch (Throwable) {
                // Lock TTLs remain a bounded fallback if explicit release fails.
            }
        }
    }

    public function __destruct()
    {
        $this->release();
    }
}
