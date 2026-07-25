<?php

namespace App\Models\Iptv;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IptvPlaybackAttempt extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'iptv_playback_session_id',
        'user_id',
        'channel_id',
        'outcome',
        'upstream_status',
        'error_code',
    ];

    public function session(): BelongsTo
    {
        return $this->belongsTo(IptvPlaybackSession::class, 'iptv_playback_session_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function channel(): BelongsTo
    {
        return $this->belongsTo(Channel::class);
    }
}
