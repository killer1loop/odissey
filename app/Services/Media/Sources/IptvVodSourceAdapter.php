<?php

namespace App\Services\Media\Sources;

use App\Models\MediaSource;
use App\Services\Iptv\ConfidentialHttpFactory;
use App\Services\Iptv\IptvVodStreamUrlFactory;
use App\Services\Iptv\PinnedUpstreamTarget;
use App\Services\Iptv\UpstreamUrlGuard;
use GuzzleHttp\Handler\StreamHandler;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;
use Illuminate\Http\Client\Response;
use JsonException;
use RuntimeException;
use Throwable;

class IptvVodSourceAdapter implements MediaSourceAdapter
{
    public function __construct(
        private readonly ConfidentialHttpFactory $http,
        private readonly UpstreamUrlGuard $urlGuard,
        private readonly IptvVodStreamUrlFactory $urlFactory,
    ) {}

    public function objects(MediaSource $source): iterable
    {
        return [];
    }

    public function capabilities(MediaSource $source): array
    {
        $provider = $source->iptvProvider;

        if ($provider === null || ! $provider->enabled) {
            throw new RuntimeException('source_unavailable');
        }

        return ['range' => true, 'seekable' => true, 'read_only' => true];
    }

    public function open(
        MediaSource $source,
        string $locator,
        ?int $start,
        ?int $end,
    ): SourceResponse {
        $provider = $source->iptvProvider;
        if ($provider === null || ! $provider->enabled || ! $source->enabled) {
            throw new RuntimeException('source_unavailable');
        }

        $locator = $this->parseLocator($locator);
        $url = $this->urlFactory->make(
            $provider,
            $locator['type'],
            $locator['id'],
            $locator['extension'],
        );
        $target = $this->urlGuard->pin(
            $url,
            $provider->allow_insecure_http,
        );
        $range = $start === null
            ? null
            : 'bytes='.$start.'-'.($end ?? '');
        $redirects = 0;
        $maximumRedirects = min(
            3,
            max(0, (int) config('iptv.playback_max_redirects', 2)),
        );

        while (true) {
            $response = $this->request($target, $range);

            if (! in_array($response->status(), [301, 302, 303, 307, 308], true)) {
                break;
            }

            if ($redirects >= $maximumRedirects) {
                $response->toPsrResponse()->getBody()->close();

                throw new RuntimeException('source_read_failed');
            }

            $location = $response->header('Location');
            $response->toPsrResponse()->getBody()->close();
            if (! is_string($location) || $location === '') {
                throw new RuntimeException('source_read_failed');
            }

            try {
                $redirectUrl = (string) UriResolver::resolve(
                    new Uri($target->url),
                    new Uri($location),
                );
                $target = $this->urlGuard->pin(
                    $redirectUrl,
                    $provider->allow_insecure_http,
                );
            } catch (Throwable) {
                throw new RuntimeException('source_read_failed');
            }
            $redirects++;
        }

        if ($response->status() === 416) {
            $response->toPsrResponse()->getBody()->close();

            throw new RuntimeException('source_range_invalid');
        }

        if (! in_array($response->status(), [200, 206], true)) {
            $response->toPsrResponse()->getBody()->close();

            throw new RuntimeException('source_read_failed');
        }

        return new SourceResponse(
            body: $response->toPsrResponse()->getBody(),
            status: $response->status(),
            size: $this->positiveInteger($response->header('Content-Length')),
            contentType: $response->header(
                'Content-Type',
                'application/octet-stream',
            ),
            contentRange: $response->header('Content-Range') ?: null,
        );
    }

    public function localPath(MediaSource $source, string $locator): ?string
    {
        return null;
    }

    /**
     * @return array{type: string, id: string, extension: string}
     */
    private function parseLocator(string $locator): array
    {
        if ($locator === '' || strlen($locator) > 512) {
            throw new RuntimeException('source_read_failed');
        }

        try {
            $decoded = json_decode($locator, true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new RuntimeException('source_read_failed');
        }

        $type = $decoded['type'] ?? null;
        $id = $decoded['id'] ?? null;
        $extension = strtolower((string) ($decoded['extension'] ?? ''));

        if (
            ! is_string($type)
            || ! in_array($type, ['movie', 'episode'], true)
            || (! is_string($id) && ! is_int($id))
            || trim((string) $id) === ''
            || strlen((string) $id) > 255
            || preg_match('/^[a-z0-9]{1,8}$/', $extension) !== 1
        ) {
            throw new RuntimeException('source_read_failed');
        }

        return [
            'type' => $type,
            'id' => trim((string) $id),
            'extension' => $extension,
        ];
    }

    private function request(
        PinnedUpstreamTarget $target,
        ?string $range,
    ): Response {
        $connectTimeout = min(
            10,
            max(1, (int) config('iptv.connect_timeout_seconds', 5)),
        );
        $request = $this->http
            ->createPendingRequest()
            ->setHandler(new StreamHandler)
            ->withHeaders([
                'Accept' => '*/*',
                'Host' => $target->authority(),
                'User-Agent' => 'Odissey Media Proxy',
            ])
            ->connectTimeout($connectTimeout)
            ->timeout($connectTimeout)
            ->withOptions(array_merge([
                'allow_redirects' => false,
                'http_errors' => false,
                'stream' => true,
                'read_timeout' => min(
                    60,
                    max(1, (int) config('iptv.stream_timeout_seconds', 45)),
                ),
            ], $target->streamOptions()));

        if ($range !== null) {
            $request = $request->withHeader('Range', $range);
        }

        try {
            return $request->get($target->connectUrl());
        } catch (Throwable) {
            throw new RuntimeException('source_read_failed');
        }
    }

    private function positiveInteger(mixed $value): int
    {
        return is_string($value) && ctype_digit($value)
            ? max(0, (int) $value)
            : 0;
    }
}
