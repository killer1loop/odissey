<?php

namespace App\Services\Iptv;

use App\Models\Iptv\IptvPlaybackAttempt;
use App\Models\Iptv\IptvPlaybackSession;
use Illuminate\Support\Facades\DB;

class PlaybackAttemptRecorder
{
    public function record(
        IptvPlaybackSession $session,
        string $outcome,
        ?int $upstreamStatus = null,
        ?string $errorCode = null,
    ): void {
        $outcome = in_array($outcome, ['started', 'failed'], true)
            ? $outcome
            : 'failed';

        DB::transaction(function () use (
            $session,
            $outcome,
            $upstreamStatus,
            $errorCode,
        ): void {
            $session->refresh();

            if (
                $session->status === 'invalidated'
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

            $session->forceFill([
                'status' => $outcome === 'started' ? 'playing' : 'failed',
                'attempt_count' => min($maxAttempts, $session->attempt_count + 1),
                'last_outcome' => $outcome,
                'last_error_code' => $errorCode,
                'started_at' => $outcome === 'started'
                    ? ($session->started_at ?? now())
                    : $session->started_at,
                'last_accessed_at' => now(),
            ])->save();
        });
    }
}
