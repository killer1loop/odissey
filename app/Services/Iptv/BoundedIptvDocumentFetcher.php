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
        $maxBytes = max(1, $maxBytes);
        $target = $this->urlGuard->pin($url, $allowInsecureHttp);
        $curlOptions = $target->curlOptions();
        $sink = new BoundedResponseSink($maxBytes);
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

            throw new SanitizedIptvException(
                $limitExceeded ? $invalidCode : $unavailableCode,
                $limitExceeded ? $invalidStatus : $unavailableStatus,
            );
        }

        if (! $response->successful()) {
            $response->toPsrResponse()->getBody()->close();
            $sink->close();

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

            throw new SanitizedIptvException($invalidCode, $invalidStatus);
        }

        try {
            $body = $sink->contents();
            if ($body === '') {
                $body = $response->body();
            }
        } catch (Throwable) {
            $response->toPsrResponse()->getBody()->close();
            $sink->close();

            throw new SanitizedIptvException($unavailableCode, $unavailableStatus);
        }

        $response->toPsrResponse()->getBody()->close();
        $sink->close();

        if (strlen($body) > $maxBytes) {
            throw new SanitizedIptvException($invalidCode, $invalidStatus);
        }

        return $body;
    }
}
