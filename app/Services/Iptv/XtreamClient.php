<?php

namespace App\Services\Iptv;

use App\Models\Iptv\IptvProvider;
use App\Services\Iptv\Exceptions\SanitizedIptvException;
use GuzzleHttp\Handler\CurlHandler;
use JsonException;
use Psr\Http\Message\ResponseInterface;
use Throwable;

class XtreamClient
{
    /**
     * json_decode expands provider payloads into PHP hash tables. A small hard
     * ceiling prevents configured limits from exceeding worker memory.
     */
    private const MAX_BUFFERED_JSON_BYTES = 32 * 1024 * 1024;

    private const MAX_CHANNEL_ROWS = 50000;

    private const MAX_VOD_ROWS = 50000;

    private const MAX_SERIES_ROWS = 20000;

    public function __construct(
        private readonly ConfidentialHttpFactory $http,
        private readonly UpstreamUrlGuard $urlGuard,
    ) {}

    /**
     * @return array<int, array<string, mixed>>
     */
    public function categories(IptvProvider $provider): array
    {
        $payload = $this->request($provider, 'get_live_categories');

        if (! array_is_list($payload)) {
            throw new SanitizedIptvException('provider_invalid_catalog');
        }

        if (
            count($payload)
            > min(10000, max(1, (int) config('iptv.category_max_rows')))
        ) {
            throw new SanitizedIptvException('provider_category_limit');
        }

        return $payload;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function liveStreams(IptvProvider $provider): array
    {
        $payload = $this->request($provider, 'get_live_streams');

        if (! array_is_list($payload)) {
            throw new SanitizedIptvException('provider_invalid_catalog');
        }

        if (
            count($payload)
            > min(
                self::MAX_CHANNEL_ROWS,
                max(1, (int) config('iptv.channel_max_rows')),
            )
        ) {
            throw new SanitizedIptvException('provider_channel_limit');
        }

        return $payload;
    }

    /** @return array<int, array<string, mixed>> */
    public function vodCategories(IptvProvider $provider): array
    {
        return $this->catalogList(
            $provider,
            'get_vod_categories',
            'iptv.vod_category_max_rows',
            10000,
            'provider_vod_category_limit',
        );
    }

    /** @return array<int, array<string, mixed>> */
    public function vodStreams(IptvProvider $provider): array
    {
        return $this->catalogList(
            $provider,
            'get_vod_streams',
            'iptv.vod_movie_max_rows',
            self::MAX_VOD_ROWS,
            'provider_vod_movie_limit',
        );
    }

    /** @return array<int, array<string, mixed>> */
    public function seriesCategories(IptvProvider $provider): array
    {
        return $this->catalogList(
            $provider,
            'get_series_categories',
            'iptv.vod_category_max_rows',
            10000,
            'provider_vod_category_limit',
        );
    }

    /** @return array<int, array<string, mixed>> */
    public function series(IptvProvider $provider): array
    {
        return $this->catalogList(
            $provider,
            'get_series',
            'iptv.vod_series_max_rows',
            self::MAX_SERIES_ROWS,
            'provider_vod_series_limit',
        );
    }

    /** @return array<string, mixed> */
    public function seriesInfo(IptvProvider $provider, string $seriesId): array
    {
        if ($seriesId === '' || strlen($seriesId) > 255) {
            throw new SanitizedIptvException('provider_invalid_series');
        }

        $payload = $this->request($provider, 'get_series_info', [
            'series_id' => $seriesId,
            'series' => $seriesId,
        ]);

        if (! isset($payload['episodes']) || ! is_array($payload['episodes'])) {
            throw new SanitizedIptvException('provider_invalid_series');
        }

        return $payload;
    }

    public function authenticate(IptvProvider $provider): ?int
    {
        $payload = $this->request($provider);
        $userInfo = $payload['user_info'] ?? null;

        if (
            ! is_array($userInfo)
            || (string) ($userInfo['auth'] ?? '0') !== '1'
        ) {
            throw new SanitizedIptvException('provider_authentication_failed', 422);
        }

        $maxConnections = $userInfo['max_connections'] ?? null;

        if (
            (! is_int($maxConnections) && ! is_string($maxConnections))
            || ! ctype_digit((string) $maxConnections)
        ) {
            return null;
        }

        $maxConnections = (int) $maxConnections;

        return $maxConnections >= 1 && $maxConnections <= 100
            ? $maxConnections
            : null;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function shortEpg(IptvProvider $provider, string $streamId, int $limit): array
    {
        $payload = $this->request($provider, 'get_short_epg', [
            'stream_id' => $streamId,
            'limit' => max(1, min($limit, 10)),
        ]);

        $listings = $payload['epg_listings'] ?? [];

        if (! is_array($listings) || ! array_is_list($listings) || count($listings) > 10) {
            throw new SanitizedIptvException('provider_invalid_guide');
        }

        return $listings;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function catalogList(
        IptvProvider $provider,
        string $action,
        string $configuredLimit,
        int $hardLimit,
        string $limitError,
    ): array {
        $payload = $this->request($provider, $action);

        if (! array_is_list($payload)) {
            throw new SanitizedIptvException('provider_invalid_catalog');
        }

        if (
            count($payload)
            > min($hardLimit, max(1, (int) config($configuredLimit)))
        ) {
            throw new SanitizedIptvException($limitError);
        }

        return $payload;
    }

    /**
     * @param  array<string, int|string>  $parameters
     * @return array<int|string, mixed>
     */
    private function request(
        IptvProvider $provider,
        ?string $action = null,
        array $parameters = [],
    ): array {
        $baseUrl = $this->urlGuard->normalizeBaseUrl(
            $provider->base_url,
            $provider->allow_insecure_http,
        );

        $query = [
            'username' => $provider->username,
            'password' => $provider->password,
            ...$parameters,
        ];

        if ($action !== null) {
            $query['action'] = $action;
        }

        $maxBytes = min(
            self::MAX_BUFFERED_JSON_BYTES,
            max(1024 * 1024, (int) config(
                'iptv.api_max_response_bytes',
                self::MAX_BUFFERED_JSON_BYTES,
            )),
        );
        $sink = new BoundedResponseSink($maxBytes);

        try {
            $target = $this->urlGuard->pin(
                $baseUrl.'/player_api.php',
                $provider->allow_insecure_http,
            );
            $response = $this->http
                ->createPendingRequest()
                ->setHandler(new CurlHandler)
                ->acceptJson()
                ->connectTimeout(min(5, max(1, (int) config('iptv.connect_timeout_seconds', 5))))
                ->timeout(min(20, max(1, (int) config('iptv.request_timeout_seconds', 20))))
                ->withOptions([
                    'allow_redirects' => false,
                    'decode_content' => true,
                    'http_errors' => false,
                    'sink' => $sink,
                    'curl' => $target->curlOptions(),
                    'on_headers' => static function (ResponseInterface $response) use ($maxBytes): void {
                        $contentLength = $response->getHeaderLine('Content-Length');

                        if (
                            ctype_digit($contentLength)
                            && (int) $contentLength > $maxBytes
                        ) {
                            throw new SanitizedIptvException('provider_response_too_large');
                        }
                    },
                    'progress' => static function (
                        int $downloadTotal,
                        int $downloadedBytes,
                        int $_uploadTotal,
                        int $_uploadedBytes,
                    ) use ($maxBytes): void {
                        if (
                            $downloadTotal > $maxBytes
                            || $downloadedBytes > $maxBytes
                        ) {
                            throw new SanitizedIptvException('provider_response_too_large');
                        }
                    },
                ])
                ->get($target->url, $query);
        } catch (SanitizedIptvException $exception) {
            $sink->close();

            throw $exception;
        } catch (Throwable) {
            $limitExceeded = $sink->limitExceeded();
            $sink->close();

            if ($limitExceeded) {
                throw new SanitizedIptvException('provider_response_too_large');
            }

            throw new SanitizedIptvException('provider_connection_failed');
        }

        if (! $response->successful()) {
            $response->toPsrResponse()->getBody()->close();
            $sink->close();

            throw new SanitizedIptvException('provider_rejected_request');
        }

        $contentLength = $response->header('Content-Length');

        if (
            is_string($contentLength)
            && ctype_digit($contentLength)
            && (int) $contentLength > $maxBytes
        ) {
            $response->toPsrResponse()->getBody()->close();
            $sink->close();

            throw new SanitizedIptvException('provider_response_too_large');
        }

        try {
            $body = $sink->contents();
            if ($body === '') {
                $body = $response->body();
            }
        } finally {
            $response->toPsrResponse()->getBody()->close();
            $sink->close();
        }

        if (strlen($body) > $maxBytes) {
            throw new SanitizedIptvException('provider_response_too_large');
        }

        try {
            $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new SanitizedIptvException('provider_invalid_response');
        }

        if (! is_array($decoded)) {
            throw new SanitizedIptvException('provider_invalid_response');
        }

        return $decoded;
    }
}
