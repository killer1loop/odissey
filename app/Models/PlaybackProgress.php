<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'user_id',
    'media_item_id',
    'position_ms',
    'duration_ms',
    'sequence',
    'completed',
])]
class PlaybackProgress extends Model
{
    protected $table = 'playback_progress';

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
            'duration_ms' => 'integer',
            'sequence' => 'integer',
            'completed' => 'boolean',
        ];
    }
}
