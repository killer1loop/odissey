<?php

namespace App\Models\Iptv;

use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class IptvPlaybackSession extends Model
{
    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'user_id',
        'channel_id',
        'status',
        'attempt_count',
        'resource_count',
        'last_outcome',
        'last_error_code',
        'started_at',
        'last_accessed_at',
        'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'last_accessed_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function channel(): BelongsTo
    {
        return $this->belongsTo(Channel::class);
    }

    public function resources(): HasMany
    {
        return $this->hasMany(IptvPlaybackResource::class);
    }

    public function attempts(): HasMany
    {
        return $this->hasMany(IptvPlaybackAttempt::class);
    }
}
