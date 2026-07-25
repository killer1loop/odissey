<?php

namespace App\Services\Media;

use Illuminate\Contracts\Cache\Lock;
use Illuminate\Support\Facades\Cache;

class TranscodeConcurrencyGate
{
    public function acquire(int $leaseSeconds): ?Lock
    {
        $slots = max(1, (int) config('odissey.max_transcodes', 1));

        for ($slot = 1; $slot <= $slots; $slot++) {
            $lock = Cache::lock(
                'odissey:media:transcode-slot:'.$slot,
                max(1, $leaseSeconds),
            );

            if ($lock->get()) {
                return $lock;
            }
        }

        return null;
    }
}
