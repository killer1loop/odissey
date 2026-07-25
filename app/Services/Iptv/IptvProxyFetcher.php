<?php

namespace App\Services\Iptv;

use App\Models\Iptv\IptvPlaybackResource;
use App\Services\Iptv\Exceptions\SanitizedIptvException;
use App\Services\Iptv\Exceptions\UpstreamResponseException;
use Illuminate\Http\Client\Response;
use Throwable;

class IptvProxyFetcher
{
    public function __construct(
        private readonly ConfidentialHttpFactory $http,
        private readonly UpstreamUrlGuard $urlGuard,
    ) {}

    public function fetch(
        IptvPlaybackResource $resource,
        ?string $range = null,
    ): Response {
        $resource->loadMissing('session.channel.provider');
        $provider = $resource->session->channel->provider;

        if (
            $resource->session->status === 'invalidated'
            || $resource->session->expires_at->isPast()
            || ! $resource->session->channel->is_active
            || ! $provider->enabled
        ) {
            throw new SanitizedIptvException('playback_source_disabled', 410);
        }

        $target = $this->urlGuard->pin(
            $resource->upstream_url,
            $provider->allow_insecure_http,
        );

        $request = $this->http
            ->withHeaders([
                'Accept' => '*/*',
                'User-Agent' => 'Odissey IPTV Proxy',
            ])
            ->connectTimeout(min(10, max(1, (int) config('iptv.connect_timeout_seconds'))))
            ->timeout(min(60, max(1, (int) config('iptv.stream_timeout_seconds'))))
            ->withOptions([
                'allow_redirects' => false,
                'http_errors' => false,
                'stream' => true,
                'curl' => $target->curlOptions(),
            ]);

        if ($range !== null) {
            if (preg_match('/^bytes=(?:\d+-\d*|-\d+)$/', $range) !== 1) {
                throw new SanitizedIptvException('invalid_range', 416);
            }

            $request = $request->withHeader('Range', $range);
        }

        try {
            $response = $request->get($target->url);
        } catch (Throwable) {
            throw new SanitizedIptvException('upstream_connection_failed');
        }

        if (! in_array($response->status(), [200, 206], true)) {
            throw new UpstreamResponseException(
                'upstream_stream_unavailable',
                $response->status(),
            );
        }

        return $response;
    }

    public function bodyWithinLimit(Response $response, int $maxBytes): string
    {
        $maxBytes = min(8 * 1024 * 1024, max(1, $maxBytes));
        $stream = $response->toPsrResponse()->getBody();
        $body = '';

        while (! $stream->eof()) {
            $chunk = $stream->read(min(64 * 1024, $maxBytes + 1 - strlen($body)));
            $body .= $chunk;

            if (strlen($body) > $maxBytes) {
                $stream->close();

                throw new SanitizedIptvException('manifest_too_large');
            }
        }

        $stream->close();

        return $body;
    }
}
