<?php

namespace App\Services\Iptv;

use App\Models\Iptv\IptvPlaybackResource;
use App\Models\Iptv\IptvPlaybackSession;
use App\Models\User;

class PlaybackAccess
{
    public function assertSession(User $user, IptvPlaybackSession $session): void
    {
        abort_unless($session->user_id === $user->id, 404);
        $session->loadMissing('channel.provider');

        if (
            $session->status === 'invalidated'
            || ! $session->channel->is_active
            || ! $session->channel->provider->enabled
        ) {
            if ($session->status !== 'invalidated') {
                $session->forceFill([
                    'status' => 'invalidated',
                    'last_error_code' => 'playback_source_disabled',
                    'expires_at' => now(),
                ])->save();
            }

            abort(410, 'Playback source unavailable.');
        }

        abort_if($session->expires_at->isPast(), 410, 'Playback session expired.');
    }

    public function assertResource(
        IptvPlaybackSession $session,
        IptvPlaybackResource $resource,
    ): void {
        abort_unless(
            $resource->iptv_playback_session_id === $session->id,
            404,
        );
        abort_if($resource->expires_at->isPast(), 410, 'Playback resource expired.');
    }

    public function touch(
        IptvPlaybackSession $session,
        IptvPlaybackResource $resource,
    ): void {
        $threshold = now()->subSeconds(30);

        if (
            $session->last_accessed_at === null
            || $session->last_accessed_at->lt($threshold)
        ) {
            $session->forceFill(['last_accessed_at' => now()])->save();
        }

        if (
            $resource->last_accessed_at === null
            || $resource->last_accessed_at->lt($threshold)
        ) {
            $resource->forceFill(['last_accessed_at' => now()])->save();
        }
    }
}
