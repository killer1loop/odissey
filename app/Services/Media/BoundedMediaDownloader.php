<?php

namespace App\Services\Media;

use App\Services\Iptv\BoundedResponseSink;
use App\Services\Iptv\ConfidentialHttpFactory;
use App\Services\Media\Sources\HttpSourceGuard;
use GuzzleHttp\Handler\CurlHandler;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;
use Illuminate\Http\Client\Response;
use RuntimeException;
use Throwable;

class BoundedMediaDownloader
{
    public function __construct(
        private readonly HttpSourceGuard $guard,
        private readonly ConfidentialHttpFactory $http,
    ) {}

    /**
     * Download a small remote asset while pinning every redirect hop.
     *
     * @param  callable(string): bool  $allowedHost
     * @param  array<string, string>  $headers
     * @return array{body: string, content_type: string, final_url: string}
     */
    public function download(
        string $url,
        int $maxBytes,
        callable $allowedHost,
        array $headers = [],
        int $maxRedirects = 2,
        int $timeoutSeconds = 30,
    ): array {
        // Every caller receives the completed response as a PHP string.
        // Clamp operator configuration below the worker memory limit.
        $maxBytes = min(16 * 1024 * 1024, max(1, $maxBytes));
        $maxRedirects = min(3, max(0, $maxRedirects));
        $timeoutSeconds = min(60, max(1, $timeoutSeconds));
        $currentUrl = $url;
        $currentHeaders = $headers;
        $redirects = 0;

        while (true) {
            $host = $this->allowedHost($currentUrl, $allowedHost);

            try {
                $target = $this->guard->pin($currentUrl, false);
            } catch (Throwable) {
                throw new RuntimeException('remote_download_rejected');
            }

            if (! hash_equals($host, $target->host)) {
                throw new RuntimeException('remote_download_rejected');
            }

            $sink = new BoundedResponseSink($maxBytes);

            try {
                $response = $this->http
                    ->createPendingRequest()
                    ->setHandler(new CurlHandler)
                    ->timeout($timeoutSeconds)
                    ->connectTimeout(min(10, $timeoutSeconds))
                    ->withoutRedirecting()
                    ->withOptions([
                        'decode_content' => true,
                        'sink' => $sink,
                        'curl' => $target->curlOptions(),
                    ])
                    ->withHeaders($currentHeaders)
                    ->get($target->url);
            } catch (Throwable) {
                $limitExceeded = $sink->limitExceeded();
                $sink->close();

                if ($limitExceeded) {
                    throw new RuntimeException('remote_download_too_large');
                }

                throw new RuntimeException('remote_download_failed');
            }

            if ($this->isRedirect($response->status())) {
                $location = $response->header('Location');
                $this->close($response);
                $sink->close();

                if (
                    $redirects >= $maxRedirects
                    || ! is_string($location)
                    || $location === ''
                ) {
                    throw new RuntimeException('remote_download_redirect_rejected');
                }

                try {
                    $nextUrl = (string) UriResolver::resolve(
                        new Uri($target->url),
                        new Uri($location),
                    );
                } catch (Throwable) {
                    throw new RuntimeException('remote_download_redirect_rejected');
                }

                $nextHost = $this->allowedHost($nextUrl, $allowedHost);
                if (! hash_equals($this->origin($target->url), $this->origin($nextUrl))) {
                    $currentHeaders = $this->safeCrossOriginHeaders($currentHeaders);
                }

                $currentUrl = $nextUrl;
                $redirects++;

                if ($nextHost === '') {
                    throw new RuntimeException('remote_download_redirect_rejected');
                }

                continue;
            }

            if ($response->status() < 200 || $response->status() >= 300) {
                $this->close($response);
                $sink->close();

                throw new RuntimeException('remote_download_failed');
            }

            try {
                return [
                    'body' => $this->bodyWithinLimit(
                        $response,
                        $sink,
                        $maxBytes,
                    ),
                    'content_type' => (string) $response->header('Content-Type', ''),
                    'final_url' => $target->url,
                ];
            } finally {
                $sink->close();
            }
        }
    }

    /**
     * @param  callable(string): bool  $allowedHost
     */
    private function allowedHost(string $url, callable $allowedHost): string
    {
        try {
            $parts = parse_url($url);
        } catch (Throwable) {
            throw new RuntimeException('remote_download_rejected');
        }

        $host = strtolower(rtrim(trim((string) ($parts['host'] ?? ''), '[]'), '.'));
        if (
            ! is_array($parts)
            || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || $host === ''
            || isset($parts['user'])
            || isset($parts['pass'])
            || ! $allowedHost($host)
        ) {
            throw new RuntimeException('remote_download_rejected');
        }

        return $host;
    }

    private function origin(string $url): string
    {
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        $host = strtolower(rtrim(trim((string) parse_url($url, PHP_URL_HOST), '[]'), '.'));
        $port = parse_url($url, PHP_URL_PORT) ?: ($scheme === 'https' ? 443 : 80);

        return $scheme.'://'.$host.':'.$port;
    }

    /**
     * @param  array<string, string>  $headers
     * @return array<string, string>
     */
    private function safeCrossOriginHeaders(array $headers): array
    {
        return array_filter(
            $headers,
            fn (string $name): bool => in_array(
                strtolower($name),
                ['accept', 'user-agent'],
                true,
            ),
            ARRAY_FILTER_USE_KEY,
        );
    }

    private function bodyWithinLimit(
        Response $response,
        BoundedResponseSink $sink,
        int $maxBytes,
    ): string {
        $contentLength = $response->header('Content-Length');
        if (
            is_string($contentLength)
            && ctype_digit($contentLength)
            && (int) $contentLength > $maxBytes
        ) {
            $this->close($response);

            throw new RuntimeException('remote_download_too_large');
        }

        try {
            $body = $sink->contents();
            if ($body === '') {
                $body = $response->body();
            }
        } finally {
            $this->close($response);
        }

        if ($sink->limitExceeded() || strlen($body) > $maxBytes) {
            throw new RuntimeException('remote_download_too_large');
        }

        return $body;
    }

    private function close(Response $response): void
    {
        try {
            $response->toPsrResponse()->getBody()->close();
        } catch (Throwable) {
            // The response is already unusable; never expose transport details.
        }
    }

    private function isRedirect(int $status): bool
    {
        return in_array($status, [301, 302, 303, 307, 308], true);
    }
}
