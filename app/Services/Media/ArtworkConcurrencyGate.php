<?php

namespace App\Services\Media;

use Illuminate\Contracts\Cache\Lock;
use Illuminate\Support\Facades\Cache;
use Throwable;

class ArtworkConcurrencyGate
{
    public function acquire(int $leaseSeconds): ?Lock
    {
        $slots = min(
            8,
            max(
                1,
                (int) config('odissey.artwork_max_processes', 2),
            ),
        );

        try {
            for ($slot = 1; $slot <= $slots; $slot++) {
                $lock = $this->lock(
                    'odissey:media:artwork-process:'.$slot,
                    $leaseSeconds,
                );

                if ($lock->get()) {
                    return $lock;
                }
            }
        } catch (Throwable) {
            return null;
        }

        return null;
    }

    public function acquireVariant(
        string $variantKey,
        int $leaseSeconds,
    ): ?Lock {
        try {
            $lock = $this->lock(
                'odissey:media:artwork-variant:'
                    .hash('sha256', $variantKey),
                $leaseSeconds,
            );

            return $lock->get() ? $lock : null;
        } catch (Throwable) {
            return null;
        }
    }

    private function lock(string $key, int $leaseSeconds): Lock
    {
        return Cache::store(
            (string) config('odissey.runtime_cache_store', 'file'),
        )->lock(
            $key,
            min(120, max(31, $leaseSeconds)),
        );
    }
}
