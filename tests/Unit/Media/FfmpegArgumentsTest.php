<?php

namespace Tests\Unit\Media;

use App\Services\Media\FfmpegArguments;
use Tests\TestCase;

class FfmpegArgumentsTest extends TestCase
{
    public function test_hls_command_is_an_argument_vector_with_browser_compatible_codecs(): void
    {
        $source = '/external/video with spaces;touch should-not-run.mkv';
        $arguments = (new FfmpegArguments)->hls(
            $source,
            '/cache/index.m3u8',
            '/cache/segment-%05d.ts',
        );

        $this->assertSame('ffmpeg', $arguments[0]);
        $this->assertContains($source, $arguments);
        $this->assertContains('libx264', $arguments);
        $this->assertContains('aac', $arguments);
        $this->assertContains('independent_segments', $arguments);
        $this->assertNotContains('sh', $arguments);
        $this->assertNotContains('-c', $arguments);
    }
}
