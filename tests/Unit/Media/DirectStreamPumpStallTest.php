<?php

namespace Tests\Unit\Media;

use App\Services\Media\DirectStreamLease;
use App\Services\Media\DirectStreamPump;
use GuzzleHttp\Psr7\StreamDecoratorTrait;
use GuzzleHttp\Psr7\Utils;
use Psr\Http\Message\StreamInterface;
use Tests\TestCase;

class DirectStreamPumpTest extends TestCase
{
    public function test_slow_upstreams_stream_beyond_a_few_empty_reads(): void
    {
        $body = new class implements StreamInterface
        {
            use StreamDecoratorTrait;

            public int $emptyReads = 0;

            public function __construct()
            {
                $this->stream = Utils::streamFor('rest-of-media');
            }

            public function eof(): bool
            {
                return $this->stream->eof();
            }

            public function read($length): string
            {
                if ($this->emptyReads < 5) {
                    $this->emptyReads++;

                    return '';
                }

                return $this->stream->read($length);
            }
        };

        ob_start();

        try {
            (new DirectStreamPump)->pump(
                $body,
                new DirectStreamLease([], 10),
                1024,
            );
        } finally {
            $output = ob_get_clean();
        }

        $this->assertSame(5, $body->emptyReads);
        $this->assertSame('rest-of-media', $output);
    }
}
