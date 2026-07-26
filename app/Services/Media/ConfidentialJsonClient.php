<?php

namespace App\Services\Media;

use App\Services\Iptv\BoundedResponseSink;
use App\Services\Iptv\ConfidentialHttpFactory;
use App\Services\Media\Sources\HttpSourceGuard;
use GuzzleHttp\Handler\CurlHandler;
use Psr\Http\Message\ResponseInterface;
use Throwable;

final class ConfidentialJsonClient
{
    public function __construct(
        private readonly HttpSourceGuard $guard,
        private readonly ConfidentialHttpFactory $http,
    ) {}

    /**
     * @param  array<string, scalar|null>  $query
     * @param  array<string, string>  $headers
     * @param  list<string>  $allowedHosts
     * @return array<string, mixed>|null
     */
    public function get(
        string $url,
        array $query,
        array $headers,
        array $allowedHosts,
    ): ?array {
        return $this->request(
            method: 'GET',
            url: $url,
            options: ['query' => $query],
            headers: $headers,
            allowedHosts: $allowedHosts,
        );
    }

    /**
     * @param  array<string, mixed>  $json
     * @param  array<string, string>  $headers
     * @param  list<string>  $allowedHosts
     * @return array<string, mixed>|null
     */
    public function post(
        string $url,
        array $json,
        array $headers,
        array $allowedHosts,
    ): ?array {
        return $this->request(
            method: 'POST',
            url: $url,
            options: ['json' => $json],
            headers: $headers,
            allowedHosts: $allowedHosts,
        );
    }

    /**
     * @param  array<string, mixed>  $options
     * @param  array<string, string>  $headers
     * @param  list<string>  $allowedHosts
     * @return array<string, mixed>|null
     */
    private function request(
        string $method,
        string $url,
        array $options,
        array $headers,
        array $allowedHosts,
    ): ?array {
        $maximumBytes = min(
            8 * 1024 * 1024,
            max(
                1024,
                (int) config(
                    'odissey.provider_json_max_bytes',
                    4 * 1024 * 1024,
                ),
            ),
        );
        $sink = new BoundedResponseSink($maximumBytes);

        try {
            $host = strtolower(rtrim(
                trim((string) parse_url($url, PHP_URL_HOST), '[]'),
                '.',
            ));
            $allowedHosts = array_map(
                static fn (string $allowed): string => strtolower(
                    rtrim($allowed, '.'),
                ),
                $allowedHosts,
            );

            if (
                $host === ''
                || ! in_array($host, $allowedHosts, true)
                || strtolower((string) parse_url($url, PHP_URL_SCHEME)) !== 'https'
            ) {
                return null;
            }

            $target = $this->guard->pin($url, false);
            if (! hash_equals($host, $target->host)) {
                return null;
            }

            $response = $this->http
                ->createPendingRequest()
                ->setHandler(new CurlHandler)
                ->acceptJson()
                ->withHeaders([
                    'Host' => $target->authority(),
                    ...$headers,
                ])
                ->connectTimeout(5)
                ->timeout(15)
                ->withoutRedirecting()
                ->withOptions([
                    'decode_content' => true,
                    'http_errors' => false,
                    'protocols' => ['https'],
                    'sink' => $sink,
                    'curl' => $target->curlOptions(),
                    'on_headers' => static function (
                        ResponseInterface $response,
                    ) use ($maximumBytes): void {
                        $contentLength = $response->getHeaderLine(
                            'Content-Length',
                        );

                        if (
                            ctype_digit($contentLength)
                            && (int) $contentLength > $maximumBytes
                        ) {
                            throw new \LengthException(
                                'Provider response exceeded its configured byte limit.',
                            );
                        }
                    },
                ])
                ->send($method, $target->url, $options);

            if ($response->status() < 200 || $response->status() >= 300) {
                $response->toPsrResponse()->getBody()->close();

                return null;
            }

            $body = $sink->contents();
            if ($body === '') {
                $body = $response->body();
            }
            $response->toPsrResponse()->getBody()->close();

            if (
                $sink->limitExceeded()
                || strlen($body) > $maximumBytes
            ) {
                return null;
            }

            $decoded = json_decode($body, true, 64, JSON_THROW_ON_ERROR);

            return is_array($decoded) ? $decoded : null;
        } catch (Throwable) {
            return null;
        } finally {
            $sink->close();
        }
    }
}
