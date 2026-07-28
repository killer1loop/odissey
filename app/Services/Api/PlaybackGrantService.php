<?php

namespace App\Services\Api;

use App\Models\NativeClientSession;
use App\Models\NativePlaybackGrant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PlaybackGrantService
{
    public const MAX_WINDOW_MINUTES = 10;

    /**
     * @return array{grant: NativePlaybackGrant, token: string}
     */
    public function issue(
        NativeClientSession $clientSession,
        string $resourceType,
        string $resourceId,
        string $deliveryMode,
        ?string $playbackReference = null,
    ): array {
        return DB::transaction(function () use (
            $clientSession,
            $resourceType,
            $resourceId,
            $deliveryMode,
            $playbackReference,
        ): array {
            $lockedSession = NativeClientSession::query()
                ->whereKey($clientSession->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $now = now();
            NativePlaybackGrant::query()
                ->where(
                    'native_client_session_id',
                    $clientSession->getKey(),
                )
                ->where('user_id', $clientSession->user_id)
                ->where('resource_type', $resourceType)
                ->where('resource_id', $resourceId)
                ->whereNull('revoked_at')
                ->where('expires_at', '>', $now)
                ->update([
                    'revoked_at' => $now,
                    'expires_at' => $now,
                    'updated_at' => $now,
                ]);

            $expiresAt = $now->copy()->addMinutes(
                $this->windowMinutes('playback_grant_minutes'),
            );
            if ($expiresAt->gt($lockedSession->refresh_expires_at)) {
                $expiresAt = $lockedSession->refresh_expires_at;
            }

            $grant = new NativePlaybackGrant([
                'native_client_session_id' => $clientSession->getKey(),
                'user_id' => $clientSession->user_id,
                'resource_type' => $resourceType,
                'resource_id' => $resourceId,
                'delivery_mode' => $deliveryMode,
                'playback_reference' => $playbackReference,
                'expires_at' => $expiresAt,
            ]);
            $grant->id = (string) Str::ulid();
            $token = Str::random(64);
            $grant->token_hash = hash('sha256', $token);
            $grant->save();

            return compact('grant', 'token');
        }, 3);
    }

    public function renewalMinutes(): int
    {
        return $this->windowMinutes('playback_renewal_minutes');
    }

    public function verify(
        string $grantId,
        string $token,
    ): ?NativePlaybackGrant {
        if (
            preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/i', $grantId) !== 1
            || preg_match('/^[A-Za-z0-9]{64}$/', $token) !== 1
        ) {
            return null;
        }

        $grant = NativePlaybackGrant::query()
            ->with(['clientSession.user', 'user'])
            ->find($grantId);

        if (
            $grant === null
            || ! $grant->isUsable()
            || ! hash_equals($grant->token_hash, hash('sha256', $token))
        ) {
            return null;
        }

        if (
            $grant->last_used_at === null
            || $grant->last_used_at->lt(now()->subMinutes(5))
        ) {
            $grant->forceFill(['last_used_at' => now()])->save();
        }

        return $grant;
    }

    private function windowMinutes(string $configuration): int
    {
        return min(
            self::MAX_WINDOW_MINUTES,
            max(1, (int) config("native-client.{$configuration}")),
        );
    }
}
