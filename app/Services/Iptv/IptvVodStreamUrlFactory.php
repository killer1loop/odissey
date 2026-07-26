<?php

namespace App\Services\Iptv;

use App\Models\Iptv\IptvProvider;
use App\Services\Iptv\Exceptions\SanitizedIptvException;

class IptvVodStreamUrlFactory
{
    public function __construct(
        private readonly UpstreamUrlGuard $urlGuard,
    ) {}

    public function make(
        IptvProvider $provider,
        string $type,
        string $streamId,
        string $extension,
    ): string {
        if (
            ! in_array($type, ['movie', 'episode'], true)
            || $streamId === ''
            || strlen($streamId) > 255
            || preg_match('/^[a-z0-9]{1,8}$/', $extension) !== 1
        ) {
            throw new SanitizedIptvException('stream_unavailable', 404);
        }

        $baseUrl = $this->urlGuard->normalizeBaseUrl(
            $provider->base_url,
            $provider->allow_insecure_http,
        );

        return sprintf(
            '%s/%s/%s/%s/%s.%s',
            $baseUrl,
            $type === 'movie' ? 'movie' : 'series',
            rawurlencode($provider->username),
            rawurlencode($provider->password),
            rawurlencode($streamId),
            $extension,
        );
    }
}
