<?php

namespace App\Models\Iptv;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ChannelGroup extends Model
{
    protected $fillable = [
        'iptv_provider_id',
        'external_id',
        'name',
        'sort_order',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(IptvProvider::class, 'iptv_provider_id');
    }

    public function channels(): HasMany
    {
        return $this->hasMany(Channel::class);
    }
}
