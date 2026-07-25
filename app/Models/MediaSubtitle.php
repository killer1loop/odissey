<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['media_item_id', 'provider', 'external_id', 'language', 'label', 'path', 'hearing_impaired', 'metadata'])]
class MediaSubtitle extends Model
{
    use HasUlids;

    public function mediaItem(): BelongsTo
    {
        return $this->belongsTo(MediaItem::class);
    }

    protected function casts(): array
    {
        return ['path' => 'encrypted', 'metadata' => 'encrypted:array', 'hearing_impaired' => 'boolean'];
    }
}
