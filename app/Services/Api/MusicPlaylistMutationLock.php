<?php

namespace App\Services\Api;

use App\Models\User;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

class MusicPlaylistMutationLock
{
    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public function synchronized(User $user, callable $callback): mixed
    {
        $lock = null;

        try {
            $lock = Cache::store(
                (string) config('odissey.runtime_cache_store', 'file'),
            )->lock(
                'odissey:api:music-playlist-user:'
                    .hash('sha256', (string) $user->getKey()),
                min(
                    120,
                    max(
                        10,
                        (int) config(
                            'native-client.music_playlist_lock_seconds',
                            30,
                        ),
                    ),
                ),
            );
            $lock->block(min(
                5,
                max(
                    0,
                    (int) config(
                        'native-client.music_playlist_lock_wait_seconds',
                        2,
                    ),
                ),
            ));
        } catch (LockTimeoutException) {
            throw new HttpException(
                409,
                'Another music playlist change is already in progress.',
                null,
                ['Retry-After' => '1'],
            );
        } catch (Throwable $exception) {
            throw new HttpException(
                503,
                'Music playlist changes are temporarily unavailable.',
                $exception,
                ['Retry-After' => '1'],
            );
        }

        try {
            return $callback();
        } finally {
            if ($lock instanceof Lock) {
                try {
                    $lock->release();
                } catch (Throwable) {
                    // The finite lease prevents a permanent lock.
                }
            }
        }
    }
}
