<?php

namespace App\Services\Media;

use App\Models\MediaItem;
use App\Models\MediaSource;
use App\Services\Media\Sources\MediaSourceRegistry;
use App\Services\Media\Sources\SourceResponse;
use Carbon\CarbonImmutable;
use Generator;
use RuntimeException;
use Throwable;

class RemoteMediaProbe
{
    public const VERSION = 1;

    private const MAXIMUM_BYTES = 16 * 1024 * 1024;

    public function __construct(
        private readonly MediaSourceRegistry $registry,
        private readonly MediaProbe $probe,
    ) {}

    /** @return array<string, mixed>|null */
    public function inspect(
        MediaSource $source,
        string $locator,
        string $path,
    ): ?array {
        if (! in_array($source->type, [
            MediaSource::TYPE_S3,
            MediaSource::TYPE_WEBDAV,
        ], true)) {
            return null;
        }

        $maximumBytes = min(
            self::MAXIMUM_BYTES,
            max(
                1024 * 1024,
                (int) config(
                    'odissey.remote_probe_max_bytes',
                    self::MAXIMUM_BYTES,
                ),
            ),
        );
        $response = null;

        try {
            $response = $this->registry
                ->for($source)
                ->open($source, $locator, 0, $maximumBytes - 1);

            return $this->probe->inspectInput(
                $this->boundedChunks($response->body, $maximumBytes),
                $path,
            );
        } catch (Throwable) {
            return null;
        } finally {
            if ($response instanceof SourceResponse) {
                $this->closeBody($response->body);
            }
        }
    }

    public function isCurrent(MediaItem $item): bool
    {
        return (int) (
            $item->metadata['technical_probe_version'] ?? 0
        ) >= self::VERSION;
    }

    public function shouldAttempt(MediaItem $item): bool
    {
        if ($this->isCurrent($item)) {
            return false;
        }

        if (
            (int) (
                $item->metadata['technical_probe_attempt_version'] ?? 0
            ) < self::VERSION
        ) {
            return true;
        }

        $attemptedAt = $item->metadata['technical_probe_attempted_at'] ?? null;
        if (! is_string($attemptedAt) || trim($attemptedAt) === '') {
            return true;
        }

        try {
            $attemptedAt = CarbonImmutable::parse($attemptedAt);
        } catch (Throwable) {
            return true;
        }

        $retryDays = min(
            365,
            max(
                1,
                (int) config('odissey.remote_probe_retry_days', 30),
            ),
        );

        return $attemptedAt->lessThanOrEqualTo(
            CarbonImmutable::now()->subDays($retryDays),
        );
    }

    /** @return Generator<int, string> */
    private function boundedChunks(mixed $body, int $maximumBytes): Generator
    {
        if (is_string($body)) {
            $chunk = substr($body, 0, $maximumBytes);
            if ($chunk !== '') {
                yield $chunk;
            }

            return;
        }

        if (
            ! is_resource($body)
            && ! (
                is_object($body)
                && method_exists($body, 'read')
                && method_exists($body, 'eof')
            )
        ) {
            throw new RuntimeException('remote_probe_input_invalid');
        }

        $remaining = $maximumBytes;
        $emptyReads = 0;

        while ($remaining > 0 && ! $this->bodyAtEof($body)) {
            $length = min(64 * 1024, $remaining);
            $chunk = is_resource($body)
                ? fread($body, $length)
                : $body->read($length);

            if (! is_string($chunk) || $chunk === '') {
                if (++$emptyReads >= 3) {
                    throw new RuntimeException('remote_probe_read_failed');
                }

                continue;
            }

            $emptyReads = 0;
            $remaining -= strlen($chunk);

            yield $chunk;
        }
    }

    private function bodyAtEof(mixed $body): bool
    {
        return is_resource($body) ? feof($body) : $body->eof();
    }

    private function closeBody(mixed $body): void
    {
        try {
            if (is_object($body) && method_exists($body, 'close')) {
                $body->close();
            } elseif (is_resource($body)) {
                fclose($body);
            }
        } catch (Throwable) {
            //
        }
    }
}
