<?php

namespace App\Services\Iptv;

use App\Models\Iptv\Channel;
use App\Models\Iptv\IptvPlaybackResource;
use App\Models\Iptv\IptvPlaybackSession;
use App\Models\User;
use App\Services\Iptv\Exceptions\SanitizedIptvException;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

class PlaybackSessionManager
{
    public function __construct(
        private readonly ProviderStreamUrlFactory $streamUrlFactory,
        private readonly PlaybackResourceRepository $resources,
        private readonly PlaybackSessionLease $lease,
    ) {}

    public function create(User $user, Channel $channel): IptvPlaybackSession
    {
        $channel->loadMissing('provider');
        $lock = Cache::store(
            (string) config('iptv.lock_store', 'file'),
        )->lock(
            "odissey:iptv:provider-session-mutation:{$channel->provider->id}",
            10,
        );
        $acquired = false;

        try {
            $acquired = $lock->get();
        } catch (Throwable) {
            throw new SanitizedIptvException('provider_session_busy', 429);
        }

        if (! $acquired) {
            throw new SanitizedIptvException('provider_session_busy', 429);
        }

        try {
            return DB::transaction(
                fn (): IptvPlaybackSession => $this->createLocked(
                    $user,
                    $channel,
                ),
            );
        } finally {
            try {
                $lock->release();
            } catch (Throwable) {
                // The lock has a short TTL and cannot monopolize provider setup.
            }
        }
    }

    public function release(IptvPlaybackSession $session): void
    {
        DB::transaction(function () use ($session): void {
            $current = IptvPlaybackSession::query()
                ->whereKey($session->id)
                ->lockForUpdate()
                ->first();

            if ($current !== null) {
                $this->lease->release($current);
            }
        });
    }

    public function rootResource(
        IptvPlaybackSession $session,
    ): IptvPlaybackResource {
        return $session->resources()
            ->whereNull('parent_resource_id')
            ->where('resource_type', 'playlist')
            ->firstOrFail();
    }

    private function createLocked(
        User $user,
        Channel $channel,
    ): IptvPlaybackSession {
        $now = CarbonImmutable::now();
        $cutoff = $this->lease->cutoff();
        $providerId = $channel->provider->id;

        $this->expireInactiveProviderSessions($providerId, $now, $cutoff);

        $userSessions = $this->activeProviderSessions(
            $providerId,
            $now,
            $cutoff,
        )
            ->where('user_id', $user->id)
            ->lockForUpdate()
            ->latest('created_at')
            ->get();
        $reusable = $userSessions->first(
            fn (IptvPlaybackSession $session): bool => $session->channel_id === $channel->id,
        );

        foreach ($userSessions as $existing) {
            if ($reusable !== null && $existing->is($reusable)) {
                continue;
            }

            $this->lease->release($existing);
        }

        if ($reusable !== null) {
            $this->lease->renew($reusable, force: true);

            if (
                ! $reusable->resources()
                    ->whereNull('parent_resource_id')
                    ->where('resource_type', 'playlist')
                    ->exists()
            ) {
                $this->resources->create(
                    $reusable,
                    $this->streamUrlFactory->forChannel($channel),
                    'playlist',
                );
            }

            return $reusable->fresh();
        }

        $limit = min(
            100,
            max(1, (int) (
                $channel->provider->config['max_connections']
                ?? config('iptv.provider_max_connections')
            )),
        );
        $active = $this->activeProviderSessions(
            $providerId,
            $now,
            $cutoff,
        )
            ->lockForUpdate()
            ->count();

        if ($active >= $limit) {
            throw new SanitizedIptvException(
                'provider_connection_limit',
                429,
            );
        }

        $session = IptvPlaybackSession::query()->create([
            'user_id' => $user->id,
            'channel_id' => $channel->id,
            'status' => 'created',
            'expires_at' => $this->lease->expiresAt(),
        ]);

        $this->resources->create(
            $session,
            $this->streamUrlFactory->forChannel($channel),
            'playlist',
        );

        return $session;
    }

    private function expireInactiveProviderSessions(
        int $providerId,
        CarbonImmutable $now,
        CarbonImmutable $cutoff,
    ): void {
        $inactiveIds = IptvPlaybackSession::query()
            ->whereHas(
                'channel',
                fn (Builder $query): Builder => $query->where(
                    'iptv_provider_id',
                    $providerId,
                ),
            )
            ->where(function (Builder $query) use ($cutoff, $now): void {
                $query
                    ->whereNotIn('status', ['created', 'playing'])
                    ->orWhere('expires_at', '<=', $now)
                    ->orWhere(function (Builder $query) use ($cutoff): void {
                        $query->where('status', 'created')
                            ->where('created_at', '<', $cutoff);
                    })
                    ->orWhere(function (Builder $query) use ($cutoff): void {
                        $query->where('status', 'playing')
                            ->where(function (Builder $query) use ($cutoff): void {
                                $query->whereNull('last_accessed_at')
                                    ->orWhere('last_accessed_at', '<', $cutoff);
                            });
                    });
            })
            ->lockForUpdate()
            ->pluck('id');

        if ($inactiveIds->isEmpty()) {
            return;
        }

        IptvPlaybackSession::query()
            ->whereKey($inactiveIds)
            ->whereIn('status', ['created', 'playing'])
            ->update([
                'status' => 'released',
                'last_error_code' => null,
                'last_accessed_at' => $now,
                'expires_at' => $now,
                'updated_at' => $now,
            ]);
        IptvPlaybackSession::query()
            ->whereKey($inactiveIds)
            ->whereNotIn('status', ['created', 'playing'])
            ->update([
                'expires_at' => $now,
                'updated_at' => $now,
            ]);
        IptvPlaybackResource::query()
            ->whereIn('iptv_playback_session_id', $inactiveIds)
            ->update([
                'expires_at' => $now,
                'updated_at' => $now,
            ]);
    }

    private function activeProviderSessions(
        int $providerId,
        CarbonImmutable $now,
        CarbonImmutable $cutoff,
    ): Builder {
        return IptvPlaybackSession::query()
            ->whereHas(
                'channel',
                fn (Builder $query): Builder => $query->where(
                    'iptv_provider_id',
                    $providerId,
                ),
            )
            ->whereIn('status', ['created', 'playing'])
            ->where('expires_at', '>', $now)
            ->where(function (Builder $query) use ($cutoff): void {
                $query
                    ->where(function (Builder $query) use ($cutoff): void {
                        $query->where('status', 'created')
                            ->where('created_at', '>=', $cutoff);
                    })
                    ->orWhere(function (Builder $query) use ($cutoff): void {
                        $query->where('status', 'playing')
                            ->whereNotNull('last_accessed_at')
                            ->where('last_accessed_at', '>=', $cutoff);
                    });
            });
    }
}
