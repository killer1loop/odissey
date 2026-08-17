<?php

namespace App\Services\Media;

use GuzzleHttp\Psr7\StreamDecoratorTrait;
use Psr\Http\Message\StreamInterface;
use RuntimeException;

final class BoundedSourceStream implements StreamInterface
{
    use StreamDecoratorTrait;

    private int $bytesRead = 0;

    public function __construct(
        private StreamInterface $stream,
        private readonly int $maximumBytes,
    ) {
        if ($maximumBytes < 1) {
            throw new RuntimeException('source_unavailable');
        }
    }

    public function getSize(): ?int
    {
        $size = $this->stream->getSize();

        return $size === null ? null : min($size, $this->maximumBytes);
    }

    public function eof(): bool
    {
        if ($this->stream->eof()) {
            return true;
        }

        if ($this->bytesRead < $this->maximumBytes) {
            return false;
        }

        $this->assertUnderlyingStreamEnded();

        return true;
    }

    public function read($length): string
    {
        if (! is_int($length) || $length < 0) {
            throw new RuntimeException('source_read_failed');
        }

        $remaining = $this->maximumBytes - $this->bytesRead;
        if ($remaining < 1) {
            $this->assertUnderlyingStreamEnded();

            return '';
        }

        $chunk = $this->stream->read(min($length, $remaining));
        $this->bytesRead += strlen($chunk);

        return $chunk;
    }

    private function assertUnderlyingStreamEnded(): void
    {
        $extraByte = $this->stream->read(1);
        if ($extraByte !== '' || ! $this->stream->eof()) {
            throw new RuntimeException('remote_source_too_large');
        }
    }
}
