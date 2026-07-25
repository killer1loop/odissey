<?php

namespace App\Services\Iptv;

use App\Models\Iptv\IptvPlaybackResource;
use App\Models\Iptv\IptvPlaybackSession;
use App\Services\Iptv\Exceptions\SanitizedIptvException;
use Illuminate\Database\QueryException;

class PlaybackResourceRepository
{
    public function create(
        IptvPlaybackSession $session,
        string $upstreamUrl,
        string $resourceType,
        ?IptvPlaybackResource $parent = null,
    ): IptvPlaybackResource {
        $fingerprint = hash_hmac(
            'sha256',
            $upstreamUrl,
            (string) config('app.key', 'odissey-resource-fingerprint'),
        );

        $existing = IptvPlaybackResource::query()
            ->where('iptv_playback_session_id', $session->id)
            ->where('upstream_fingerprint', $fingerprint)
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        $depth = $parent?->depth ?? 0;

        if ($resourceType === 'playlist' && $parent !== null) {
            $depth++;
        }

        if (
            $depth
            > min(12, max(1, (int) config('iptv.playlist_max_depth')))
        ) {
            throw new SanitizedIptvException('playlist_depth_limit');
        }

        $reserved = IptvPlaybackSession::query()
            ->whereKey($session->id)
            ->where(
                'resource_count',
                '<',
                min(8192, max(2, (int) config('iptv.playback_max_resources'))),
            )
            ->increment('resource_count');

        if ($reserved !== 1) {
            throw new SanitizedIptvException('playback_resource_limit');
        }

        try {
            $resource = IptvPlaybackResource::query()->create([
                'iptv_playback_session_id' => $session->id,
                'parent_resource_id' => $parent?->id,
                'upstream_fingerprint' => $fingerprint,
                'upstream_url' => $upstreamUrl,
                'resource_type' => $resourceType,
                'depth' => $depth,
                'expires_at' => $session->expires_at,
            ]);

            $session->setAttribute(
                'resource_count',
                (int) $session->resource_count + 1,
            );

            return $resource;
        } catch (QueryException $exception) {
            IptvPlaybackSession::query()
                ->whereKey($session->id)
                ->where('resource_count', '>', 0)
                ->decrement('resource_count');

            $existing = IptvPlaybackResource::query()
                ->where('iptv_playback_session_id', $session->id)
                ->where('upstream_fingerprint', $fingerprint)
                ->first();

            if ($existing !== null) {
                return $existing;
            }

            throw $exception;
        }
    }

    public function promoteToPlaylist(
        IptvPlaybackResource $resource,
    ): IptvPlaybackResource {
        if ($resource->resource_type === 'playlist') {
            return $resource;
        }

        $resource->loadMissing('parent');
        $depth = ($resource->parent?->depth ?? 0) + 1;

        if (
            $depth
            > min(12, max(1, (int) config('iptv.playlist_max_depth')))
        ) {
            throw new SanitizedIptvException('playlist_depth_limit');
        }

        $resource->forceFill([
            'resource_type' => 'playlist',
            'depth' => $depth,
        ])->save();

        return $resource;
    }
}
