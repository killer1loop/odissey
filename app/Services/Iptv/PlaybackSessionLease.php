<?php

namespace App\Services\Iptv;

use App\Models\Iptv\IptvPlaybackSession;
use Carbon\CarbonImmutable;

class PlaybackSessionLease
{
    public function seconds(): int
    {
        return min(
            300,
            max(30, (int) config('iptv.playback_lease_seconds', 90)),
        );
    }

    public function cutoff(): CarbonImmutable
    {
        return CarbonImmutable::now()->subSeconds($this->seconds());
    }

    public function expiresAt(): CarbonImmutable
    {
        return CarbonImmutable::now()->addSeconds($this->seconds());
    }

    public function renew(
        IptvPlaybackSession $session,
        bool $force = false,
    ): void {
        $now = CarbonImmutable::now();
        $threshold = $now->subSeconds(
            min(30, max(5, intdiv($this->seconds(), 3))),
        );

        if (
            ! $force
            && $session->last_accessed_at !== null
            && $session->last_accessed_at->gte($threshold)
            && $session->expires_at->gt($now->addSeconds(intdiv($this->seconds(), 2)))
        ) {
            return;
        }

        $expiresAt = $now->addSeconds($this->seconds());
        $session->forceFill([
            'last_accessed_at' => $now,
            'expires_at' => $expiresAt,
        ])->save();
        $session->resources()
            ->where('expires_at', '<', $expiresAt)
            ->update([
                'expires_at' => $expiresAt,
                'updated_at' => $now,
            ]);
    }

    public function release(
        IptvPlaybackSession $session,
        string $status = 'released',
        ?string $errorCode = null,
    ): void {
        $now = CarbonImmutable::now();
        $session->forceFill([
            'status' => $status,
            'last_error_code' => $errorCode,
            'expires_at' => $now,
            'last_accessed_at' => $now,
        ])->save();
        $session->resources()->update([
            'expires_at' => $now,
            'updated_at' => $now,
        ]);
    }
}
