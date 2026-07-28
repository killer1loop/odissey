<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'native_client_session_id',
    'token_hash',
    'used_at',
])]
#[Hidden(['token_hash'])]
class NativeRefreshTokenUse extends Model
{
    public $timestamps = false;

    public function clientSession(): BelongsTo
    {
        return $this->belongsTo(
            NativeClientSession::class,
            'native_client_session_id',
        );
    }

    protected function casts(): array
    {
        return [
            'used_at' => 'immutable_datetime',
        ];
    }
}
