<?php

namespace App\Services\Iptv;

use App\Models\Iptv\IptvPlaybackResource;
use App\Services\Iptv\Exceptions\SanitizedIptvException;
use App\Services\Iptv\Exceptions\UpstreamResponseException;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;
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

        if ($range !== null) {
            if (preg_match('/^bytes=(?:\d+-\d*|-\d+)$/', $range) !== 1) {
                throw new SanitizedIptvException('invalid_range', 416);
            }
        }

        $allowInsecureHttp = $provider->allow_insecure_http;
        $target = $this->urlGuard->pin(
            $resource->upstream_url,
            $allowInsecureHttp,
        );
        $maxRedirects = min(
            3,
            max(0, (int) config('iptv.playback_max_redirects')),
        );
        $redirects = 0;

        while (true) {
            $response = $this->request($target, $range);

            if (! $this->isRedirect($response->status())) {
                break;
            }

            if ($redirects >= $maxRedirects) {
                $response->toPsrResponse()->getBody()->close();

                throw new SanitizedIptvException('upstream_redirect_limit');
            }

            $location = $response->header('Location');
            $response->toPsrResponse()->getBody()->close();

            if (! is_string($location) || $location === '') {
                throw new SanitizedIptvException('invalid_upstream_redirect');
            }

            try {
                $redirectUrl = (string) UriResolver::resolve(
                    new Uri($target->url),
                    new Uri($location),
                );
            } catch (Throwable) {
                throw new SanitizedIptvException('invalid_upstream_redirect');
            }

            $target = $this->urlGuard->pin($redirectUrl, $allowInsecureHttp);
            $redirects++;
        }

        if (! in_array($response->status(), [200, 206], true)) {
            $response->toPsrResponse()->getBody()->close();

            throw new UpstreamResponseException(
                'upstream_stream_unavailable',
                $response->status(),
            );
        }

        return $response;
    }

    private function request(PinnedUpstreamTarget $target, ?string $range): Response
    {
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
            $request = $request->withHeader('Range', $range);
        }

        try {
            return $request->get($target->url);
        } catch (Throwable) {
            throw new SanitizedIptvException('upstream_connection_failed');
        }
    }

    private function isRedirect(int $status): bool
    {
        return in_array($status, [301, 302, 303, 307, 308], true);
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
