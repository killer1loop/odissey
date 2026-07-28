<?php

namespace App\Services\Api;

use App\Models\NativeClientSession;
use App\Models\NativeRefreshTokenUse;
use App\Models\User;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class NativeTokenService
{
    /**
     * @param  array{
     *     installationId: string,
     *     deviceName: string,
     *     platform: string,
     *     appVersion: string,
     *     osVersion?: string|null
     * }  $device
     * @return array{session: NativeClientSession, accessToken: string, refreshToken: string}
     */
    public function issue(User $user, array $device): array
    {
        return DB::transaction(function () use ($user, $device): array {
            $installationHash = hash('sha256', $device['installationId']);

            NativeClientSession::query()
                ->whereBelongsTo($user)
                ->where('installation_id_hash', $installationHash)
                ->whereNull('revoked_at')
                ->lockForUpdate()
                ->get()
                ->each->revoke();

            $session = new NativeClientSession([
                'user_id' => $user->getKey(),
                'installation_id_hash' => $installationHash,
                'device_name' => $device['deviceName'],
                'platform' => $device['platform'],
                'app_version' => $device['appVersion'],
                'os_version' => $device['osVersion'] ?? null,
                'access_expires_at' => now()->addMinutes(
                    (int) config('native-client.access_token_minutes'),
                ),
                'refresh_expires_at' => now()->addDays(
                    (int) config('native-client.refresh_token_days'),
                ),
            ]);
            $session->id = (string) Str::ulid();

            [$accessToken, $refreshToken] = $this->freshTokens($session);
            $session->save();

            $this->enforceSessionLimit($user, $session);

            return compact('session', 'accessToken', 'refreshToken');
        }, 3);
    }

    /**
     * @return array{session: NativeClientSession, accessToken: string, refreshToken: string}
     */
    public function rotate(string $rawRefreshToken): array
    {
        $parsed = $this->parse($rawRefreshToken, 'od_rt');

        if ($parsed === null) {
            throw $this->invalidCredentials();
        }

        $result = DB::transaction(function () use (
            $rawRefreshToken,
            $parsed,
        ): array {
            $session = NativeClientSession::query()
                ->with('user')
                ->whereKey($parsed['id'])
                ->lockForUpdate()
                ->first();

            if ($session === null) {
                throw $this->invalidCredentials();
            }

            $providedHash = hash('sha256', $rawRefreshToken);
            if (
                NativeRefreshTokenUse::query()
                    ->whereBelongsTo($session, 'clientSession')
                    ->where('token_hash', $providedHash)
                    ->exists()
            ) {
                $session->revoke();

                return ['replayed' => true];
            }

            if (
                ! $session->isUsableForRefresh()
                || ! hash_equals($session->refresh_token_hash, $providedHash)
            ) {
                throw $this->invalidCredentials();
            }

            $previousRefreshHash = $session->refresh_token_hash;
            $maximumRotations = (int) config(
                'native-client.maximum_refresh_rotations_per_session',
            );
            if (
                NativeRefreshTokenUse::query()
                    ->whereBelongsTo($session, 'clientSession')
                    ->count() >= $maximumRotations
            ) {
                $session->revoke();

                return ['replayed' => true];
            }
            NativeRefreshTokenUse::query()->create([
                'native_client_session_id' => $session->getKey(),
                'token_hash' => $previousRefreshHash,
                'used_at' => now(),
            ]);
            [$accessToken, $refreshToken] = $this->freshTokens($session);
            $session->forceFill([
                'previous_refresh_token_hash' => $previousRefreshHash,
                'access_expires_at' => now()->addMinutes(
                    (int) config('native-client.access_token_minutes'),
                ),
                'last_used_at' => now(),
            ])->save();

            return [
                'replayed' => false,
                ...compact('session', 'accessToken', 'refreshToken'),
            ];
        }, 3);

        if ($result['replayed']) {
            throw $this->invalidCredentials();
        }

        unset($result['replayed']);

        return $result;
    }

    public function findForAccessToken(string $rawAccessToken): ?NativeClientSession
    {
        $parsed = $this->parse($rawAccessToken, 'od_at');
        if ($parsed === null) {
            return null;
        }

        $session = NativeClientSession::query()
            ->with('user')
            ->find($parsed['id']);

        if (
            $session === null
            || ! $session->isUsableForAccess()
            || ! hash_equals(
                $session->access_token_hash,
                hash('sha256', $rawAccessToken),
            )
        ) {
            return null;
        }

        if (
            $session->last_used_at === null
            || $session->last_used_at->lt(now()->subMinutes(5))
        ) {
            $session->forceFill(['last_used_at' => now()])->save();
        }

        return $session;
    }

    /**
     * @return array{sessionId: string, accessToken: string, refreshToken: string, tokenType: string, expiresIn: int, accessExpiresAt: string, refreshExpiresAt: string}
     */
    public function responsePayload(
        NativeClientSession $session,
        string $accessToken,
        string $refreshToken,
    ): array {
        return [
            'sessionId' => (string) $session->getKey(),
            'accessToken' => $accessToken,
            'refreshToken' => $refreshToken,
            'tokenType' => 'Bearer',
            'expiresIn' => max(0, (int) now()->diffInSeconds(
                $session->access_expires_at,
                false,
            )),
            'accessExpiresAt' => $session->access_expires_at
                ->utc()
                ->toIso8601String(),
            'refreshExpiresAt' => $session->refresh_expires_at
                ->utc()
                ->toIso8601String(),
        ];
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function freshTokens(NativeClientSession $session): array
    {
        $accessToken = $this->make('od_at', (string) $session->id);
        $refreshToken = $this->make('od_rt', (string) $session->id);
        $session->access_token_hash = hash('sha256', $accessToken);
        $session->refresh_token_hash = hash('sha256', $refreshToken);

        return [$accessToken, $refreshToken];
    }

    private function make(string $prefix, string $id): string
    {
        return $prefix.'.'.$id.'.'.Str::random(64);
    }

    /**
     * @return array{id: string, secret: string}|null
     */
    private function parse(string $token, string $expectedPrefix): ?array
    {
        $parts = explode('.', $token, 3);
        if (
            count($parts) !== 3
            || $parts[0] !== $expectedPrefix
            || preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/i', $parts[1]) !== 1
            || preg_match('/^[A-Za-z0-9]{64}$/', $parts[2]) !== 1
        ) {
            return null;
        }

        return ['id' => $parts[1], 'secret' => $parts[2]];
    }

    private function enforceSessionLimit(
        User $user,
        NativeClientSession $current,
    ): void {
        $limit = (int) config('native-client.maximum_sessions_per_user');
        $stale = NativeClientSession::query()
            ->whereBelongsTo($user)
            ->whereNull('revoked_at')
            ->whereKeyNot($current->getKey())
            ->latest('last_used_at')
            ->latest('created_at')
            ->skip(max(0, $limit - 1))
            ->take(50)
            ->get();

        $stale->each->revoke();
    }

    private function invalidCredentials(): AuthenticationException
    {
        return new AuthenticationException(
            'The supplied credentials are invalid.',
        );
    }
}
