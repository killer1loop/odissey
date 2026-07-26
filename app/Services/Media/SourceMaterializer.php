<?php

namespace App\Services\Media;

use App\Models\MediaItem;
use App\Models\MediaSource;
use App\Services\Media\Sources\MediaSourceRegistry;
use App\Services\Media\Sources\SourceResponse;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class SourceMaterializer
{
    public function __construct(
        private readonly MediaSourceRegistry $registry,
        private readonly TranscodeStorage $storage,
    ) {}

    /** @return array{path: string, temporary: bool} */
    public function materialize(MediaItem $item): array
    {
        if ($item->source === null) {
            return ['path' => $item->source_locator, 'temporary' => false];
        }

        return $this->materializeObject(
            $item->source,
            $item->source_locator,
            max(0, (int) ($item->size_bytes ?? 0)),
            $item->container ?: pathinfo(
                $item->source_locator,
                PATHINFO_EXTENSION,
            ),
        );
    }

    /**
     * Snapshot a validated source handle into controlled transient storage.
     *
     * @return array{path: string, temporary: true}
     */
    public function materializeObject(
        MediaSource $source,
        string $locator,
        int $catalogSize,
        string $extension,
    ): array {
        $adapter = $this->registry->for($source);
        $sourceLimit = min(
            16 * 1024 * 1024 * 1024,
            max(1, (int) config('odissey.remote_transcode_max_source_bytes')),
        );
        $catalogSize = max(0, $catalogSize);
        if ($catalogSize > $sourceLimit) {
            throw new RuntimeException('remote_source_too_large');
        }

        $reservation = $this->storage->reserveSourceBytes(
            $sourceLimit,
            $catalogSize > 0 ? $catalogSize : null,
        );
        if ($reservation === null) {
            throw new RuntimeException(
                $catalogSize > 0
                    ? 'remote_source_too_large'
                    : 'remote_source_capacity_exhausted',
            );
        }

        $result = null;
        $directory = $this->storage->transientDirectory(
            'sources',
            create: true,
        );
        $extension = preg_replace(
            '/[^a-z0-9]/',
            '',
            strtolower($extension ?: 'bin'),
        ) ?: 'bin';
        $path = $directory.'/snapshot-'.Str::lower((string) Str::ulid()).'.'.$extension;

        try {
            $result = $adapter->open(
                $source,
                $locator,
                null,
                null,
            );
            if (
                $result->size > 0
                && $result->size > $reservation->capacityBytes()
            ) {
                throw new RuntimeException('remote_source_too_large');
            }

            if (
                (is_object($result->body)
                    && method_exists($result->body, 'read'))
                || is_resource($result->body)
            ) {
                $this->writeStream($result, $path, $reservation);
            } else {
                $bytes = is_string($result->body) ? $result->body : (string) $result->body;
                $length = strlen($bytes);

                if (
                    $length < 1
                    || $length > $reservation->capacityBytes()
                ) {
                    throw new RuntimeException('remote_source_too_large');
                }

                if (
                    File::put($path, $bytes, true) !== $length
                    || ! chmod($path, 0600)
                ) {
                    throw new RuntimeException('remote_source_write_failed');
                }

                $reservation->consume($length);
                if (! $this->storage->isWithinStorageLimits()) {
                    throw new RuntimeException(
                        'remote_source_capacity_exhausted',
                    );
                }
            }
        } catch (Throwable $exception) {
            File::delete($path);

            throw $exception;
        } finally {
            if ($result instanceof SourceResponse) {
                $this->closeBody($result);
            }

            $reservation->release();
        }

        return ['path' => $path, 'temporary' => true];
    }

    private function writeStream(
        SourceResponse $result,
        string $path,
        TranscodeReservation $reservation,
    ): void {
        $output = @fopen($path, 'xb');

        if ($output === false) {
            throw new RuntimeException('remote_source_write_failed');
        }

        $writtenBytes = 0;
        $emptyReads = 0;
        $nextStorageCheck = 8 * 1024 * 1024;

        try {
            while (! $this->bodyAtEof($result->body)) {
                $chunk = $this->readBody($result->body, 1024 * 1024);

                if (! is_string($chunk) || $chunk === '') {
                    if (++$emptyReads >= 3) {
                        throw new RuntimeException('remote_source_read_failed');
                    }

                    continue;
                }

                $emptyReads = 0;
                $writtenBytes += strlen($chunk);
                if ($writtenBytes > $reservation->capacityBytes()) {
                    throw new RuntimeException('remote_source_too_large');
                }

                $offset = 0;
                $length = strlen($chunk);
                while ($offset < $length) {
                    $written = fwrite($output, substr($chunk, $offset));
                    if ($written === false || $written === 0) {
                        throw new RuntimeException('remote_source_write_failed');
                    }
                    $offset += $written;
                }

                $reservation->consume($length);
                if ($writtenBytes >= $nextStorageCheck) {
                    if (! $this->storage->isWithinStorageLimits()) {
                        throw new RuntimeException(
                            'remote_source_capacity_exhausted',
                        );
                    }

                    $nextStorageCheck = $writtenBytes + 8 * 1024 * 1024;
                }
            }

            if ($writtenBytes < 1 || ! fflush($output)) {
                throw new RuntimeException('remote_source_write_failed');
            }
            if (! chmod($path, 0600)) {
                throw new RuntimeException('remote_source_write_failed');
            }

            if (! $this->storage->isWithinStorageLimits()) {
                throw new RuntimeException(
                    'remote_source_capacity_exhausted',
                );
            }
        } finally {
            fclose($output);
        }
    }

    private function closeBody(SourceResponse $result): void
    {
        if (is_object($result->body) && method_exists($result->body, 'close')) {
            $result->body->close();
        } elseif (is_resource($result->body)) {
            fclose($result->body);
        }
    }

    private function bodyAtEof(mixed $body): bool
    {
        return is_resource($body) ? feof($body) : $body->eof();
    }

    private function readBody(mixed $body, int $length): string|false
    {
        return is_resource($body)
            ? fread($body, $length)
            : $body->read($length);
    }
}
