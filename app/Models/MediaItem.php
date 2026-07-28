<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\File;

#[Fillable([
    'user_id',
    'media_source_id',
    'stable_id',
    'scan_token',
    'title',
    'media_kind',
    'source_type',
    'source_locator',
    'relative_path',
    'mime_type',
    'container',
    'video_codec',
    'audio_codec',
    'duration_ms',
    'requires_transcode',
    'size_bytes',
    'source_modified_at',
    'missing_at',
    'metadata',
])]
class MediaItem extends Model
{
    use HasUlids;

    protected static function booted(): void
    {
        static::deleting(function (MediaItem $item): void {
            self::deleteAssetDirectory(
                (string) config('odissey.artwork_path'),
                (string) $item->id,
            );
            self::deleteAssetDirectory(
                (string) config('odissey.caption_path'),
                (string) $item->id,
            );
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(MediaSource::class, 'media_source_id');
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

    public function favorites(): HasMany
    {
        return $this->hasMany(MediaFavorite::class);
    }

    public function playlistItems(): HasMany
    {
        return $this->hasMany(MusicPlaylistItem::class);
    }

    public function subtitles(): HasMany
    {
        return $this->hasMany(MediaSubtitle::class);
    }

    public function isAccessibleBy(User $user): bool
    {
        return $this->media_source_id !== null || $this->user_id === $user->getKey();
    }

    public function scopeAccessibleTo($query, User $user)
    {
        return $query->where(function ($query) use ($user): void {
            $query->whereNotNull('media_source_id')
                ->orWhere('user_id', $user->getKey());
        })->whereNull('missing_at');
    }

    private static function deleteAssetDirectory(
        string $configuredRoot,
        string $itemId,
    ): void {
        $root = rtrim($configuredRoot, DIRECTORY_SEPARATOR);
        $segments = explode(DIRECTORY_SEPARATOR, $root);

        if (
            $root === ''
            || $root === DIRECTORY_SEPARATOR
            || ! str_starts_with($root, DIRECTORY_SEPARATOR)
            || str_contains($root, "\0")
            || str_contains($root, DIRECTORY_SEPARATOR.DIRECTORY_SEPARATOR)
            || in_array('.', $segments, true)
            || in_array('..', $segments, true)
            || is_link($root)
        ) {
            return;
        }

        $directory = $root.DIRECTORY_SEPARATOR.$itemId;

        if (is_link($directory)) {
            return;
        }

        if (File::isDirectory($directory)) {
            File::deleteDirectory($directory);
        }
    }

    protected function casts(): array
    {
        return [
            'source_locator' => 'encrypted',
            'duration_ms' => 'integer',
            'requires_transcode' => 'boolean',
            'size_bytes' => 'integer',
            'source_modified_at' => 'datetime',
            'missing_at' => 'datetime',
            'metadata' => 'array',
        ];
    }
}
