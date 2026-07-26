<?php

namespace App\Models\Iptv;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Channel extends Model
{
    protected $fillable = [
        'iptv_provider_id',
        'channel_group_id',
        'external_id',
        'epg_channel_id',
        'name',
        'channel_number',
        'stream_icon',
        'logo_source',
        'logo_channel_id',
        'stream_extension',
        'metadata',
        'is_active',
    ];

    protected $hidden = [
        'stream_icon',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'stream_icon' => 'encrypted',
            'metadata' => 'encrypted:array',
            'is_active' => 'boolean',
        ];
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(IptvProvider::class, 'iptv_provider_id');
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(ChannelGroup::class, 'channel_group_id');
    }

    public function programs(): HasMany
    {
        return $this->hasMany(EpgProgram::class);
    }

    public function favorites(): HasMany
    {
        return $this->hasMany(ChannelFavorite::class);
    }

    public function playbackSessions(): HasMany
    {
        return $this->hasMany(IptvPlaybackSession::class);
    }
}
