<?php

namespace Tests\Unit\Media;

use App\Services\Media\DirectStreamLease;
use App\Services\Media\DirectStreamPump;
use Tests\TestCase;

class DirectStreamPumpStallTest extends TestCase
{
    public function test_slow_upstreams_stream_beyond_a_few_empty_reads(): void
    {
        $body = new class
        {
            public int $emptyReads = 0;

            private string $payload = 'rest-of-media';

            private int $offset = 0;

            public function eof(): bool
            {
                return $this->offset >= strlen($this->payload);
            }

            public function read(int $length): string
            {
                if ($this->emptyReads < 5) {
                    $this->emptyReads++;

                    return '';
                }

                $chunk = substr($this->payload, $this->offset, $length);
                $this->offset += strlen($chunk);

                return $chunk;
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
