<?php

namespace App\Services\Iptv;

use App\Models\Iptv\IptvPlaybackResource;
use App\Models\Iptv\IptvPlaybackSession;
use App\Models\User;

class PlaybackAccess
{
    public function __construct(
        private readonly PlaybackSessionLease $lease,
    ) {}

    public function assertSession(User $user, IptvPlaybackSession $session): void
    {
        abort_unless($session->user_id === $user->id, 404);
        $session->loadMissing(['channel.provider', 'channel.group']);

        if (
            ! $session->channel->is_active
            || ! $session->channel->provider->enabled
            || (
                $session->channel->group !== null
                && ! $session->channel->group->is_active
            )
        ) {
            if (in_array($session->status, ['created', 'playing'], true)) {
                $this->lease->release(
                    $session,
                    'invalidated',
                    'playback_source_disabled',
                );
            }

            abort(410, 'Playback source unavailable.');
        }

        abort_unless(
            in_array($session->status, ['created', 'playing'], true),
            410,
            'Playback session unavailable.',
        );
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
        $this->lease->renew($session);
        $threshold = now()->subSeconds(15);

        if (
            $resource->last_accessed_at === null
            || $resource->last_accessed_at->lt($threshold)
        ) {
            $resource->forceFill(['last_accessed_at' => now()])->save();
        }
    }
}
