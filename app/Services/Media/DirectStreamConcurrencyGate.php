<?php

namespace App\Services\Media;

use Illuminate\Contracts\Cache\Lock;
use Illuminate\Support\Facades\Cache;
use Throwable;

class DirectStreamConcurrencyGate
{
    public function acquire(
        string $userId,
        string $sourceId,
    ): ?DirectStreamLease {
        $maximumLifetimeSeconds = min(
            3600,
            max(
                1,
                (int) config(
                    'odissey.remote_stream_max_seconds',
                    900,
                ),
            ),
        );
        $leaseSeconds = min(
            3660,
            max(
                $maximumLifetimeSeconds + 15,
                (int) config(
                    'odissey.remote_stream_lease_seconds',
                    915,
                ),
            ),
        );
        $startedAtNanoseconds = hrtime(true);
        $locks = [];

        try {
            foreach ([
                [
                    'key' => 'odissey:media:direct-stream:global',
                    'slots' => min(
                        256,
                        max(
                            1,
                            (int) config(
                                'odissey.remote_stream_global_concurrency',
                                32,
                            ),
                        ),
                    ),
                ],
                [
                    'key' => 'odissey:media:direct-stream:source:'
                        .hash('sha256', $sourceId),
                    'slots' => min(
                        128,
                        max(
                            1,
                            (int) config(
                                'odissey.remote_stream_source_concurrency',
                                12,
                            ),
                        ),
                    ),
                ],
                [
                    'key' => 'odissey:media:direct-stream:user:'
                        .hash('sha256', $userId),
                    'slots' => min(
                        32,
                        max(
                            1,
                            (int) config(
                                'odissey.remote_stream_user_concurrency',
                                4,
                            ),
                        ),
                    ),
                ],
            ] as $admission) {
                $lock = $this->acquireSlot(
                    $admission['key'],
                    $admission['slots'],
                    $leaseSeconds,
                );

                if ($lock === null) {
                    $this->releaseLocks($locks);

                    return null;
                }

                $locks[] = $lock;
            }

            return new DirectStreamLease(
                $locks,
                $maximumLifetimeSeconds,
                $startedAtNanoseconds,
            );
        } catch (Throwable) {
            $this->releaseLocks($locks);

            return null;
        }
    }

    private function acquireSlot(
        string $key,
        int $slots,
        int $leaseSeconds,
    ): ?Lock {
        for ($slot = 1; $slot <= $slots; $slot++) {
            $lock = Cache::lock("{$key}:{$slot}", $leaseSeconds);

            if ($lock->get()) {
                return $lock;
            }
        }

        return null;
    }

    /**
     * @param  list<Lock>  $locks
     */
    private function releaseLocks(array $locks): void
    {
        foreach (array_reverse($locks) as $lock) {
            try {
                $lock->release();
            } catch (Throwable) {
                //
            }
        }
    }
}
