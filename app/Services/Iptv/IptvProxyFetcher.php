<?php

namespace App\Services\Iptv;

use App\Models\Iptv\IptvPlaybackResource;
use App\Services\Iptv\Exceptions\SanitizedIptvException;
use App\Services\Iptv\Exceptions\UpstreamResponseException;
use GuzzleHttp\Handler\StreamHandler;
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
        $resource->loadMissing([
            'session.channel.provider',
            'session.channel.group',
        ]);
        $provider = $resource->session->channel->provider;

        if (
            ! in_array(
                $resource->session->status,
                ['created', 'playing'],
                true,
            )
            || $resource->session->expires_at->isPast()
            || ! $resource->session->channel->is_active
            || ! $provider->enabled
            || (
                $resource->session->channel->group !== null
                && ! $resource->session->channel->group->is_active
            )
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
            max(0, (int) config('iptv.playback_max_redirects', 2)),
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

        if ($redirects > 0 && $resource->upstream_url !== $target->url) {
            $resource->forceFill([
                'upstream_url' => $target->url,
            ])->saveQuietly();
        }

        return $response;
    }

    private function request(PinnedUpstreamTarget $target, ?string $range): Response
    {
        $connectTimeout = min(
            10,
            max(1, (int) config('iptv.connect_timeout_seconds', 5)),
        );
        $readTimeout = min(
            60,
            max(1, (int) config('iptv.stream_timeout_seconds', 45)),
        );
        $request = $this->http
            ->createPendingRequest()
            ->setHandler(new StreamHandler)
            ->withHeaders([
                'Accept' => '*/*',
                'Host' => $target->authority(),
                'User-Agent' => 'Odissey IPTV Proxy',
            ])
            ->connectTimeout($connectTimeout)
            ->timeout($connectTimeout)
            ->withOptions(array_merge([
                'allow_redirects' => false,
                'http_errors' => false,
                'stream' => true,
                'read_timeout' => $readTimeout,
            ], $target->streamOptions()));

        if ($range !== null) {
            $request = $request->withHeader('Range', $range);
        }

        try {
            return $request->get($target->connectUrl());
        } catch (Throwable) {
            throw new SanitizedIptvException('upstream_connection_failed');
        }
    }

    private function isRedirect(int $status): bool
    {
        return in_array($status, [301, 302, 303, 307, 308], true);
    }

    public function bodyWithinLimit(
        Response $response,
        int $maxBytes,
        string $prefix = '',
    ): string {
        $maxBytes = min(8 * 1024 * 1024, max(1, $maxBytes));
        $stream = $response->toPsrResponse()->getBody();
        $body = $prefix;
        $emptyReads = 0;

        if (strlen($body) > $maxBytes) {
            $stream->close();

            throw new SanitizedIptvException('manifest_too_large');
        }

        while (! $stream->eof()) {
            $chunk = $stream->read(min(64 * 1024, $maxBytes + 1 - strlen($body)));

            if ($chunk === '') {
                if (++$emptyReads >= 8) {
                    $stream->close();

                    throw new SanitizedIptvException('manifest_unavailable');
                }

                continue;
            }

            $emptyReads = 0;
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
