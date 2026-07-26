<?php

namespace App\Services\Media\Sources;

use App\Models\MediaSource;
use InvalidArgumentException;

class MediaSourceRegistry
{
    public function for(MediaSource $source): MediaSourceAdapter
    {
        return match ($source->type) {
            MediaSource::TYPE_LOCAL => app(LocalSourceAdapter::class),
            MediaSource::TYPE_S3 => app(S3SourceAdapter::class),
            MediaSource::TYPE_WEBDAV => app(WebDavSourceAdapter::class),
            MediaSource::TYPE_IPTV => app(IptvVodSourceAdapter::class),
            default => throw new InvalidArgumentException('Unsupported media source.'),
        };
    }
}
