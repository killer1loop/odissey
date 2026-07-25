<?php

namespace App\Services\Iptv;

use App\Models\Iptv\Channel;

class ProviderStreamUrlFactory
{
    public function __construct(
        private readonly UpstreamUrlGuard $urlGuard,
    ) {}

    public function forChannel(Channel $channel): string
    {
        $provider = $channel->provider;
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
