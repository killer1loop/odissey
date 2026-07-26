<?php

namespace App\Models\Iptv;

use App\Models\MediaSource;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class IptvProvider extends Model
{
    protected $fillable = [
        'name',
        'base_url',
        'username',
        'password',
        'config',
        'allow_insecure_http',
        'enabled',
        'sync_status',
        'last_error_code',
        'last_synced_at',
        'last_guide_synced_at',
    ];

    protected $hidden = [
        'base_url',
        'username',
        'password',
        'config',
    ];

    protected function casts(): array
    {
        return [
            'base_url' => 'encrypted',
            'username' => 'encrypted',
            'password' => 'encrypted',
            'config' => 'encrypted:array',
            'allow_insecure_http' => 'boolean',
            'enabled' => 'boolean',
            'last_synced_at' => 'datetime',
            'last_guide_synced_at' => 'datetime',
        ];
    }

    public function groups(): HasMany
    {
        return $this->hasMany(ChannelGroup::class);
    }

    public function channels(): HasMany
    {
        return $this->hasMany(Channel::class);
    }

    public function programs(): HasMany
    {
        return $this->hasMany(EpgProgram::class);
    }

    public function vodSource(): HasOne
    {
        return $this->hasOne(MediaSource::class);
    }
}
