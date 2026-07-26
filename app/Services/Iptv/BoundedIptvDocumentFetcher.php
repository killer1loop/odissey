<?php

namespace App\Services\Iptv;

use App\Services\Iptv\Exceptions\SanitizedIptvException;
use GuzzleHttp\Handler\CurlHandler;
use Psr\Http\Message\ResponseInterface;
use Throwable;

class BoundedIptvDocumentFetcher
{
    public function __construct(
        private readonly ConfidentialHttpFactory $http,
        private readonly UpstreamUrlGuard $urlGuard,
    ) {}

    public function fetch(
        string $url,
        bool $allowInsecureHttp,
        int $maxBytes,
        int $timeoutSeconds,
        string $unavailableCode,
        string $invalidCode,
        int $unavailableStatus = 502,
        int $invalidStatus = 422,
    ): string {
        $path = $this->fetchToTemporaryFile(
            url: $url,
            allowInsecureHttp: $allowInsecureHttp,
            maxBytes: $maxBytes,
            timeoutSeconds: $timeoutSeconds,
            unavailableCode: $unavailableCode,
            invalidCode: $invalidCode,
            unavailableStatus: $unavailableStatus,
            invalidStatus: $invalidStatus,
        );

        try {
            $body = file_get_contents($path);

            if ($body === false) {
                throw new SanitizedIptvException(
                    $unavailableCode,
                    $unavailableStatus,
                );
            }

            return $body;
        } finally {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }

    public function fetchToTemporaryFile(
        string $url,
        bool $allowInsecureHttp,
        int $maxBytes,
        int $timeoutSeconds,
        string $unavailableCode,
        string $invalidCode,
        int $unavailableStatus = 502,
        int $invalidStatus = 422,
    ): string {
        $maxBytes = max(1, $maxBytes);
        $target = $this->urlGuard->pin($url, $allowInsecureHttp);
        $curlOptions = $target->curlOptions();
        $path = tempnam(sys_get_temp_dir(), 'odissey-xmltv-');

        if ($path === false) {
            throw new SanitizedIptvException(
                $unavailableCode,
                $unavailableStatus,
            );
        }

        $sink = new BoundedResponseSink($maxBytes, $path);
        $headerLimitExceeded = false;

        try {
            // Guzzle's PHP stream handler does not honor CURLOPT_RESOLVE.
            // cURL writes decoded response chunks through the bounded sink.
            $request = $this->http
                ->createPendingRequest()
                ->setHandler(new CurlHandler)
                ->withHeaders([
                    'Accept' => '*/*',
                    'User-Agent' => 'Odissey IPTV Importer',
                ])
                ->connectTimeout(
                    min(10, max(1, (int) config('iptv.connect_timeout_seconds'))),
                )
                ->timeout(min(60, max(1, $timeoutSeconds)))
                ->withOptions([
                    'allow_redirects' => false,
                    'decode_content' => true,
                    'http_errors' => false,
                    'sink' => $sink,
                    'curl' => $curlOptions,
                    'on_headers' => static function (
                        ResponseInterface $response,
                    ) use ($maxBytes, &$headerLimitExceeded): void {
                        $contentLength = $response->getHeaderLine('Content-Length');

                        if (
                            ctype_digit($contentLength)
                            && (int) $contentLength > $maxBytes
                        ) {
                            $headerLimitExceeded = true;

                            throw new SanitizedIptvException('upstream_document_too_large');
                        }
                    },
                ]);

            $response = $request->get($target->url);
        } catch (SanitizedIptvException $exception) {
            $sink->close();
            $this->removeTemporaryFile($path);

            if (
                $headerLimitExceeded
                || $sink->limitExceeded()
                || $exception->errorCode === 'upstream_document_too_large'
            ) {
                throw new SanitizedIptvException($invalidCode, $invalidStatus);
            }

            throw $exception;
        } catch (Throwable) {
            $limitExceeded = $headerLimitExceeded || $sink->limitExceeded();
            $sink->close();
            $this->removeTemporaryFile($path);

            throw new SanitizedIptvException(
                $limitExceeded ? $invalidCode : $unavailableCode,
                $limitExceeded ? $invalidStatus : $unavailableStatus,
            );
        }

        if (! $response->successful()) {
            $response->toPsrResponse()->getBody()->close();
            $sink->close();
            $this->removeTemporaryFile($path);

            throw new SanitizedIptvException($unavailableCode, $unavailableStatus);
        }

        $contentLength = $response->header('Content-Length');

        if (
            is_string($contentLength)
            && ctype_digit($contentLength)
            && (int) $contentLength > $maxBytes
        ) {
            $response->toPsrResponse()->getBody()->close();
            $sink->close();
            $this->removeTemporaryFile($path);

            throw new SanitizedIptvException($invalidCode, $invalidStatus);
        }

        try {
            // Laravel's fake HTTP transport does not write through Guzzle's
            // sink, so tests and custom transports need this bounded fallback.
            if ($sink->bytesWritten() === 0) {
                $sink->write($response->body());
            }
        } catch (Throwable) {
            $response->toPsrResponse()->getBody()->close();
            $sink->close();
            $this->removeTemporaryFile($path);

            throw new SanitizedIptvException(
                $invalidCode,
                $invalidStatus,
            );
        }

        $response->toPsrResponse()->getBody()->close();
        $sink->close();

        $size = filesize($path);

        if ($size === false || $size > $maxBytes) {
            $this->removeTemporaryFile($path);

            throw new SanitizedIptvException($invalidCode, $invalidStatus);
        }

        return $path;
    }

    private function removeTemporaryFile(string $path): void
    {
        if (is_file($path)) {
            unlink($path);
        }
    }
}
