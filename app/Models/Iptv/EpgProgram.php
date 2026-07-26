<?php

namespace App\Models\Iptv;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EpgProgram extends Model
{
    protected $fillable = [
        'iptv_provider_id',
        'channel_id',
        'fingerprint',
        'sync_token',
        'upstream_event_id',
        'title',
        'description',
        'category',
        'starts_at',
        'ends_at',
    ];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
        ];
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(IptvProvider::class, 'iptv_provider_id');
    }

    public function channel(): BelongsTo
    {
        return $this->belongsTo(Channel::class);
    }
}
