<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'user_id',
    'native_client_session_id',
    'action',
    'subject_type',
    'subject_id',
    'request_id',
])]
class AdminAuditEvent extends Model
{
    use HasUlids;

    public const UPDATED_AT = null;

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function clientSession(): BelongsTo
    {
        return $this->belongsTo(
            NativeClientSession::class,
            'native_client_session_id',
        );
    }
}
