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
    'heartbeat_at',
    'finished_at',
    'expires_at',
    'profile',
    'delivery_mode',
    'audio_track',
    'subtitle_track',
    'media_subtitle_id',
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

    /**
     * Keep an actively consumed HLS output alive. Playback endpoints call
     * this so long viewings never hit the fixed post-conversion TTL; writes
     * are throttled to at most once per minute per session.
     */
    public function extendPlaybackLease(): void
    {
        if ($this->status !== self::STATUS_READY) {
            return;
        }

        if (
            $this->expires_at !== null
            && $this->expires_at->gt(now()->addMinute())
        ) {
            return;
        }

        $this->forceFill([
            'expires_at' => now()->addMinutes(
                max(
                    1,
                    (int) config('odissey.transcode_ttl_minutes', 30),
                ),
            ),
            'heartbeat_at' => now(),
        ])->save();
    }

    protected function casts(): array
    {
        return [
            'started_at' => 'immutable_datetime',
            'heartbeat_at' => 'immutable_datetime',
            'finished_at' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime',
            'audio_track' => 'integer',
            'subtitle_track' => 'integer',
        ];
    }
}
