<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable([
    'user_id',
    'title',
    'source_type',
    'source_locator',
    'mime_type',
    'container',
    'video_codec',
    'audio_codec',
    'duration_ms',
    'requires_transcode',
])]
class MediaItem extends Model
{
    use HasUlids;

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function progress(): HasOne
    {
        return $this->hasOne(PlaybackProgress::class);
    }

    public function playbackHistory(): HasMany
    {
        return $this->hasMany(PlaybackHistory::class);
    }

    public function transcodeSessions(): HasMany
    {
        return $this->hasMany(TranscodeSession::class);
    }

    protected function casts(): array
    {
        return [
            'source_locator' => 'encrypted',
            'duration_ms' => 'integer',
            'requires_transcode' => 'boolean',
        ];
    }
}
