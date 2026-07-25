<?php

namespace App\Services\Iptv;

use App\Models\Iptv\IptvProvider;
use App\Services\Iptv\Exceptions\SanitizedIptvException;
use JsonException;
use Psr\Http\Message\ResponseInterface;
use Throwable;

class XtreamClient
{
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
            > min(150000, max(1, (int) config('iptv.channel_max_rows')))
        ) {
            throw new SanitizedIptvException('provider_channel_limit');
        }

        return $payload;
    }

    public function authenticate(IptvProvider $provider): void
    {
        $payload = $this->request($provider);
        $userInfo = $payload['user_info'] ?? null;

        if (
            ! is_array($userInfo)
            || (string) ($userInfo['auth'] ?? '0') !== '1'
        ) {
            throw new SanitizedIptvException('provider_authentication_failed', 422);
        }
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
            128 * 1024 * 1024,
            max(1024 * 1024, (int) config('iptv.api_max_response_bytes')),
        );

        try {
            $target = $this->urlGuard->pin(
                $baseUrl.'/player_api.php',
                $provider->allow_insecure_http,
            );
            $response = $this->http
                ->acceptJson()
                ->connectTimeout(min(5, max(1, (int) config('iptv.connect_timeout_seconds'))))
                ->timeout(min(20, max(1, (int) config('iptv.request_timeout_seconds'))))
                ->withOptions([
                    'allow_redirects' => false,
                    'http_errors' => false,
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
            throw $exception;
        } catch (Throwable) {
            throw new SanitizedIptvException('provider_connection_failed');
        }

        if (! $response->successful()) {
            throw new SanitizedIptvException('provider_rejected_request');
        }

        $contentLength = $response->header('Content-Length');

        if (
            is_string($contentLength)
            && ctype_digit($contentLength)
            && (int) $contentLength > $maxBytes
        ) {
            throw new SanitizedIptvException('provider_response_too_large');
        }

        $body = $response->body();

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
