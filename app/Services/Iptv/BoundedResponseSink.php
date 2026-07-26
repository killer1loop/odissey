<?php

namespace App\Services\Iptv;

use GuzzleHttp\Psr7\StreamDecoratorTrait;
use GuzzleHttp\Psr7\Utils;
use LengthException;
use Psr\Http\Message\StreamInterface;
use RuntimeException;

class BoundedResponseSink implements StreamInterface
{
    use StreamDecoratorTrait;

    private StreamInterface $stream;

    private int $bytesWritten = 0;

    private bool $limitExceeded = false;

    public function __construct(
        private readonly int $maxBytes,
    ) {
        $handle = fopen('php://temp/maxmemory:2097152', 'w+b');

        if ($handle === false) {
            throw new RuntimeException('Unable to create bounded IPTV response stream.');
        }

        $this->stream = Utils::streamFor($handle);
    }

    public function write($string): int
    {
        $length = strlen($string);

        if ($length > $this->maxBytes - $this->bytesWritten) {
            $this->limitExceeded = true;

            throw new LengthException('IPTV response exceeded its configured byte limit.');
        }

        $written = $this->stream->write($string);
        $this->bytesWritten += $written;

        return $written;
    }

    public function limitExceeded(): bool
    {
        return $this->limitExceeded;
    }

    public function contents(): string
    {
        $this->stream->rewind();

        return $this->stream->getContents();
    }
}
