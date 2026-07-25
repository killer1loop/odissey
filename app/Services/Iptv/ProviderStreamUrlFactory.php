<?php

namespace App\Services\Iptv;

use App\Models\Iptv\Channel;
use App\Services\Iptv\Exceptions\SanitizedIptvException;

class ProviderStreamUrlFactory
{
    public function __construct(
        private readonly UpstreamUrlGuard $urlGuard,
    ) {}

    public function forChannel(Channel $channel): string
    {
        $provider = $channel->provider;
        if (($provider->config['api'] ?? 'xtream') === 'm3u') {
            $url = $channel->metadata['stream_url'] ?? null;
            if (! is_string($url)) {
                throw new SanitizedIptvException('stream_unavailable', 404);
            }
            $this->urlGuard->assertPublicTarget($url, $provider->allow_insecure_http);

            return $url;
        }
        $baseUrl = $this->urlGuard->normalizeBaseUrl(
            $provider->base_url,
            $provider->allow_insecure_http,
        );

        return sprintf(
            '%s/live/%s/%s/%s.m3u8',
            $baseUrl,
            rawurlencode($provider->username),
            rawurlencode($provider->password),
            rawurlencode($channel->external_id),
        );
    }
}
