<?php

namespace App\Services\Iptv;

use App\Models\Iptv\IptvPlaybackAttempt;
use App\Models\Iptv\IptvPlaybackSession;
use Illuminate\Support\Facades\DB;

class PlaybackAttemptRecorder
{
    public function __construct(
        private readonly PlaybackSessionLease $lease,
    ) {}

    public function record(
        IptvPlaybackSession $session,
        string $outcome,
        ?int $upstreamStatus = null,
        ?string $errorCode = null,
        bool $terminalOnThreshold = true,
    ): void {
        $outcome = in_array($outcome, ['started', 'failed'], true)
            ? $outcome
            : 'failed';

        DB::transaction(function () use (
            $session,
            $outcome,
            $upstreamStatus,
            $errorCode,
            $terminalOnThreshold,
        ): void {
            $session->refresh();

            if (
                ! in_array($session->status, ['created', 'playing'], true)
                || $session->expires_at->isPast()
            ) {
                return;
            }

            $maxAttempts = min(
                500,
                max(1, (int) config('iptv.playback_max_attempts')),
            );

            if ($session->attempt_count < $maxAttempts) {
                IptvPlaybackAttempt::query()->create([
                    'iptv_playback_session_id' => $session->id,
                    'user_id' => $session->user_id,
                    'channel_id' => $session->channel_id,
                    'outcome' => $outcome,
                    'upstream_status' => $upstreamStatus,
                    'error_code' => $errorCode,
                ]);
            }

            $failureCount = $outcome === 'started'
                ? 0
                : (
                    $terminalOnThreshold
                        ? min(
                            1000,
                            (int) $session->consecutive_failure_count + 1,
                        )
                        : (int) $session->consecutive_failure_count
                );
            $failureThreshold = min(
                20,
                max(
                    2,
                    (int) config('iptv.playback_failure_threshold', 3),
                ),
            );
            $terminalFailure = (
                $outcome === 'failed'
                && $terminalOnThreshold
                && $failureCount >= $failureThreshold
            );

            $session->forceFill([
                'status' => $outcome === 'started'
                    ? 'playing'
                    : ($terminalFailure ? 'failed' : $session->status),
                'attempt_count' => min($maxAttempts, $session->attempt_count + 1),
                'consecutive_failure_count' => $failureCount,
                'last_outcome' => $outcome,
                'last_error_code' => $errorCode,
                'last_failure_at' => $outcome === 'failed'
                    ? now()
                    : null,
                'started_at' => $outcome === 'started'
                    ? ($session->started_at ?? now())
                    : $session->started_at,
                'last_accessed_at' => now(),
            ])->save();

            if ($outcome === 'started') {
                $this->lease->renew($session);
            } elseif ($terminalFailure) {
                $this->lease->release($session, 'failed', $errorCode);
            }
        });
    }
}
