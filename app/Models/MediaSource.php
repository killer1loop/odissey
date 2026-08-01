<?php

namespace App\Models;

use App\Models\Iptv\IptvProvider;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'iptv_provider_id', 'name', 'type', 'configuration', 'capabilities', 'enabled',
    'allow_private_network', 'scan_status', 'active_scan_token',
    'scan_discovery_complete', 'scan_discovered', 'scan_processed',
    'scan_failed', 'scan_caption_jobs', 'scan_probe_jobs', 'last_error_code',
    'last_scanned_at',
])]
class MediaSource extends Model
{
    use HasUlids;

    public const TYPE_LOCAL = 'local';

    public const TYPE_S3 = 's3';

    public const TYPE_WEBDAV = 'webdav';

    public const TYPE_IPTV = 'iptv';

    public function items(): HasMany
    {
        return $this->hasMany(MediaItem::class);
    }

    public function iptvProvider(): BelongsTo
    {
        return $this->belongsTo(IptvProvider::class);
    }

    protected function casts(): array
    {
        return [
            'configuration' => 'encrypted:array',
            'capabilities' => 'array',
            'enabled' => 'boolean',
            'allow_private_network' => 'boolean',
            'scan_discovery_complete' => 'boolean',
            'scan_discovered' => 'integer',
            'scan_processed' => 'integer',
            'scan_failed' => 'integer',
            'scan_caption_jobs' => 'integer',
            'scan_probe_jobs' => 'integer',
            'last_scanned_at' => 'datetime',
        ];
    }
}
