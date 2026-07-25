<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'user_id',
    'media_item_id',
    'event',
    'position_ms',
    'watched_ms',
    'played_at',
])]
class PlaybackHistory extends Model
{
    use HasUlids;

    protected $table = 'playback_history';

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function mediaItem(): BelongsTo
    {
        return $this->belongsTo(MediaItem::class);
    }

    protected function casts(): array
    {
        return [
            'position_ms' => 'integer',
            'watched_ms' => 'integer',
            'played_at' => 'immutable_datetime',
        ];
    }
}
