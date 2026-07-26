<?php

namespace App\Services\Iptv;

use App\Models\Iptv\IptvPlaybackSession;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Support\Facades\Cache;
use Throwable;

class PlaybackConcurrencyGate
{
    public function acquire(
        IptvPlaybackSession $session,
    ): ?PlaybackConcurrencyLease {
        $session->loadMissing('channel.provider');
        $sessionSlots = min(
            12,
            max(2, (int) config('iptv.playback_session_concurrency', 6)),
        );
        $providerConnections = min(
            100,
            max(1, (int) (
                $session->channel->provider->config['max_connections']
                ?? config('iptv.provider_max_connections', 1)
            )),
        );
        $providerSlots = min(
            120,
            max(
                $sessionSlots,
                min(
                    max(
                        $sessionSlots,
                        (int) config('iptv.playback_provider_concurrency', 48),
                    ),
                    $providerConnections * $sessionSlots,
                ),
            ),
        );
        $streamTimeout = min(
            60,
            max(1, (int) config('iptv.stream_timeout_seconds', 45)),
        );
        $leaseSeconds = min(
            120,
            max(
                $streamTimeout + 15,
                (int) config('iptv.playback_request_lease_seconds', 75),
            ),
        );
        $startedAtNanoseconds = hrtime(true);

        try {
            $sessionLock = $this->acquireSlot(
                "odissey:iptv:session:{$session->id}",
                $sessionSlots,
                $leaseSeconds,
            );

            if ($sessionLock === null) {
                return null;
            }

            $providerLock = $this->acquireSlot(
                "odissey:iptv:provider:{$session->channel->provider->id}",
                $providerSlots,
                $leaseSeconds,
            );

            if ($providerLock === null) {
                $sessionLock->release();

                return null;
            }

            return new PlaybackConcurrencyLease(
                $sessionLock,
                $providerLock,
                $leaseSeconds,
                $startedAtNanoseconds,
            );
        } catch (Throwable) {
            if (isset($sessionLock) && $sessionLock instanceof Lock) {
                try {
                    $sessionLock->release();
                } catch (Throwable) {
                    //
                }
            }

            return null;
        }
    }

    private function acquireSlot(
        string $key,
        int $slots,
        int $leaseSeconds,
    ): ?Lock {
        for ($slot = 1; $slot <= $slots; $slot++) {
            $lock = Cache::store(
                (string) config('iptv.lock_store', 'file'),
            )->lock("{$key}:{$slot}", $leaseSeconds);

            if ($lock->get()) {
                return $lock;
            }
        }

        return null;
    }
}
