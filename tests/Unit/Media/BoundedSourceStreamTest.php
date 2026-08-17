<?php

namespace Tests\Unit\Media;

use App\Services\Media\BoundedSourceStream;
use GuzzleHttp\Psr7\Utils;
use RuntimeException;
use Tests\TestCase;

class BoundedSourceStreamTest extends TestCase
{
    public function test_source_that_ends_at_the_limit_is_complete(): void
    {
        $stream = new BoundedSourceStream(
            Utils::streamFor('four'),
            4,
        );

        $this->assertSame('four', $stream->getContents());
        $this->assertTrue($stream->eof());
    }

    public function test_source_that_continues_past_the_limit_is_rejected(): void
    {
        $stream = new BoundedSourceStream(
            Utils::streamFor('five!'),
            4,
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('remote_source_too_large');

        $stream->getContents();
    }
}
