<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'user_id',
    'installation_id_hash',
    'access_token_hash',
    'refresh_token_hash',
    'previous_refresh_token_hash',
    'device_name',
    'platform',
    'app_version',
    'os_version',
    'access_expires_at',
    'refresh_expires_at',
    'last_used_at',
    'revoked_at',
])]
#[Hidden([
    'access_token_hash',
    'refresh_token_hash',
    'previous_refresh_token_hash',
    'installation_id_hash',
])]
class NativeClientSession extends Model
{
    use HasUlids;

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function playbackGrants(): HasMany
    {
        return $this->hasMany(NativePlaybackGrant::class);
    }

    public function usedRefreshTokens(): HasMany
    {
        return $this->hasMany(
            NativeRefreshTokenUse::class,
            'native_client_session_id',
        );
    }

    public function isUsableForAccess(): bool
    {
        return $this->revoked_at === null
            && $this->access_expires_at->isFuture()
            && $this->refresh_expires_at->isFuture()
            && $this->user?->isActive();
    }

    public function isUsableForRefresh(): bool
    {
        return $this->revoked_at === null
            && $this->refresh_expires_at->isFuture()
            && $this->user?->isActive();
    }

    public function revoke(): void
    {
        if ($this->revoked_at !== null) {
            return;
        }

        $now = now();
        $this->forceFill([
            'revoked_at' => $now,
            'access_expires_at' => $now,
            'refresh_expires_at' => $now,
        ])->save();

        $this->playbackGrants()
            ->whereNull('revoked_at')
            ->update([
                'revoked_at' => $now,
                'expires_at' => $now,
                'updated_at' => $now,
            ]);
    }

    protected function casts(): array
    {
        return [
            'access_expires_at' => 'immutable_datetime',
            'refresh_expires_at' => 'immutable_datetime',
            'last_used_at' => 'immutable_datetime',
            'revoked_at' => 'immutable_datetime',
        ];
    }
}
