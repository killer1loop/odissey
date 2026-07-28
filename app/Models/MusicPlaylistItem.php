<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'music_playlist_id',
    'media_item_id',
    'position',
])]
class MusicPlaylistItem extends Model
{
    use HasUlids;

    protected function casts(): array
    {
        return [
            'position' => 'integer',
        ];
    }

    public function playlist(): BelongsTo
    {
        return $this->belongsTo(
            MusicPlaylist::class,
            'music_playlist_id',
        );
    }

    public function mediaItem(): BelongsTo
    {
        return $this->belongsTo(MediaItem::class);
    }
}
