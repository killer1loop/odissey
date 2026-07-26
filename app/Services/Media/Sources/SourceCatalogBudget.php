<?php

namespace App\Services\Media\Sources;

use Illuminate\Http\Client\Response;
use RuntimeException;
use SimpleXMLElement;
use Throwable;

final class SourceCatalogBudget
{
    private int $bytes = 0;

    private int $items = 0;

    private int $requests = 0;

    private readonly float $deadline;

    private function __construct(
        private readonly int $maxBytes,
        private readonly int $maxItems,
        private readonly int $maxRequests,
        private readonly int $maxLocatorBytes,
        int $timeoutSeconds,
    ) {
        $this->deadline = microtime(true) + $timeoutSeconds;
    }

    public static function fromConfig(?int $maxRequests = null): self
    {
        $configuredRequests = min(
            10000,
            max(1, (int) config('odissey.source_catalog_max_requests', 10000)),
        );

        return new self(
            maxBytes: min(
                4 * 1024 * 1024,
                max(
                    1024,
                    (int) config(
                        'odissey.source_catalog_max_bytes',
                        4 * 1024 * 1024,
                    ),
                ),
            ),
            maxItems: min(
                500000,
                max(1, (int) config('odissey.source_catalog_max_items', 100000)),
            ),
            maxRequests: min(
                $configuredRequests,
                max(1, $maxRequests ?? $configuredRequests),
            ),
            maxLocatorBytes: min(
                16384,
                max(255, (int) config('odissey.source_catalog_max_locator_bytes', 4096)),
            ),
            timeoutSeconds: min(
                3600,
                max(1, (int) config('odissey.source_catalog_timeout_seconds', 300)),
            ),
        );
    }

    public function consumeRequest(): void
    {
        $this->assertActive();

        if (++$this->requests > $this->maxRequests) {
            throw new RuntimeException('source_catalog_request_limit');
        }
    }

    public function consumeItem(): void
    {
        $this->assertActive();

        if (++$this->items > $this->maxItems) {
            throw new RuntimeException('source_catalog_item_limit');
        }
    }

    public function assertLocator(string $locator): void
    {
        if (
            $locator === ''
            || strlen($locator) > $this->maxLocatorBytes
            || preg_match('/[\x00-\x1F\x7F]/', $locator) === 1
        ) {
            throw new RuntimeException('source_catalog_locator_invalid');
        }
    }

    public function timeoutSeconds(int $ceiling): int
    {
        $this->assertActive();

        return max(
            1,
            min($ceiling, (int) ceil($this->deadline - microtime(true))),
        );
    }

    public function read(Response $response): string
    {
        $this->assertActive();
        $remaining = $this->maxBytes - $this->bytes;
        $contentLength = $response->header('Content-Length');

        if (
            $remaining <= 0
            || (
                is_string($contentLength)
                && ctype_digit($contentLength)
                && (int) $contentLength > $remaining
            )
        ) {
            $this->discard($response);

            throw new RuntimeException('source_catalog_byte_limit');
        }

        $stream = $response->toPsrResponse()->getBody();
        $body = '';

        try {
            while (! $stream->eof()) {
                $this->assertActive();
                $remaining = $this->maxBytes - $this->bytes;
                $chunk = $stream->read(min(64 * 1024, $remaining + 1));
                $chunkBytes = strlen($chunk);
                if ($chunkBytes === 0 && ! $stream->eof()) {
                    throw new RuntimeException('source_catalog_read_failed');
                }
                $this->bytes += $chunkBytes;
                $body .= $chunk;

                if ($this->bytes > $this->maxBytes) {
                    throw new RuntimeException('source_catalog_byte_limit');
                }
            }
        } catch (RuntimeException $exception) {
            if (str_starts_with($exception->getMessage(), 'source_catalog_')) {
                throw $exception;
            }

            throw new RuntimeException('source_catalog_read_failed');
        } catch (Throwable) {
            throw new RuntimeException('source_catalog_read_failed');
        } finally {
            $stream->close();
        }

        return $body;
    }

    public function parse(string $body, string $errorCode): SimpleXMLElement
    {
        $this->assertActive();

        if (preg_match('/<!DOCTYPE/i', $body) === 1) {
            throw new RuntimeException($errorCode);
        }

        try {
            $xml = simplexml_load_string(
                $body,
                SimpleXMLElement::class,
                LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_COMPACT,
            );
        } catch (Throwable) {
            throw new RuntimeException($errorCode);
        }

        if (! $xml instanceof SimpleXMLElement) {
            throw new RuntimeException($errorCode);
        }

        return $xml;
    }

    public function discard(Response $response): void
    {
        try {
            $response->toPsrResponse()->getBody()->close();
        } catch (Throwable) {
            // The response is already unusable.
        }
    }

    private function assertActive(): void
    {
        if (microtime(true) >= $this->deadline) {
            throw new RuntimeException('source_catalog_timeout');
        }
    }
}
