<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'user_id',
    'media_item_id',
    'status',
    'manifest_relative_path',
    'error_code',
    'started_at',
    'finished_at',
    'expires_at',
    'profile',
    'audio_track',
])]
class TranscodeSession extends Model
{
    use HasUlids;

    public const STATUS_FAILED = 'failed';

    public const STATUS_PENDING = 'pending';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_READY = 'ready';

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function mediaItem(): BelongsTo
    {
        return $this->belongsTo(MediaItem::class);
    }

    public function isAvailable(): bool
    {
        return $this->status === self::STATUS_READY
            && ($this->expires_at === null || $this->expires_at->isFuture());
    }

    protected function casts(): array
    {
        return [
            'started_at' => 'immutable_datetime',
            'finished_at' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime',
        ];
    }
}
