<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'native_client_session_id',
    'user_id',
    'token_hash',
    'resource_type',
    'resource_id',
    'delivery_mode',
    'playback_reference',
    'last_used_at',
    'expires_at',
    'revoked_at',
])]
#[Hidden(['token_hash'])]
class NativePlaybackGrant extends Model
{
    use HasUlids;

    public function clientSession(): BelongsTo
    {
        return $this->belongsTo(
            NativeClientSession::class,
            'native_client_session_id',
        );
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isUsable(): bool
    {
        return $this->revoked_at === null
            && $this->expires_at->isFuture()
            && $this->clientSession?->isUsableForRefresh()
            && $this->user?->isActive();
    }

    protected function casts(): array
    {
        return [
            'last_used_at' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime',
            'revoked_at' => 'immutable_datetime',
        ];
    }
}
