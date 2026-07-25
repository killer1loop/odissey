<?php

namespace App\Services\Iptv;

use App\Models\Iptv\Channel;
use App\Models\Iptv\IptvPlaybackResource;
use App\Models\Iptv\IptvPlaybackSession;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class PlaybackSessionManager
{
    public function __construct(
        private readonly ProviderStreamUrlFactory $streamUrlFactory,
        private readonly PlaybackResourceRepository $resources,
    ) {}

    public function create(User $user, Channel $channel): IptvPlaybackSession
    {
        $channel->loadMissing('provider');

        return DB::transaction(function () use ($user, $channel): IptvPlaybackSession {
            $session = IptvPlaybackSession::query()->create([
                'user_id' => $user->id,
                'channel_id' => $channel->id,
                'status' => 'created',
                'expires_at' => now()->addMinutes(
                    min(120, max(5, (int) config('iptv.playback_session_minutes'))),
                ),
            ]);

            $this->resources->create(
                $session,
                $this->streamUrlFactory->forChannel($channel),
                'playlist',
            );

            return $session;
        });
    }

    public function rootResource(IptvPlaybackSession $session): IptvPlaybackResource
    {
        return $session->resources()
            ->whereNull('parent_resource_id')
            ->where('resource_type', 'playlist')
            ->firstOrFail();
    }
}
