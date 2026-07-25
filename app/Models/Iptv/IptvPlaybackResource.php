<?php

namespace App\Models\Iptv;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class IptvPlaybackResource extends Model
{
    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'iptv_playback_session_id',
        'parent_resource_id',
        'upstream_fingerprint',
        'upstream_url',
        'resource_type',
        'depth',
        'content_type',
        'last_accessed_at',
        'expires_at',
    ];

    protected $hidden = [
        'upstream_url',
        'upstream_fingerprint',
    ];

    protected function casts(): array
    {
        return [
            'upstream_url' => 'encrypted',
            'last_accessed_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(IptvPlaybackSession::class, 'iptv_playback_session_id');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_resource_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_resource_id');
    }
}
