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
        $this->assertContains('-max_alloc', $arguments);
        $this->assertContains('-max_pixels', $arguments);
        $this->assertContains('-maxrate:v', $arguments);
        $this->assertContains('-filter_threads', $arguments);
        $protocolIndex = array_search('-protocol_whitelist', $arguments, true);
        $this->assertIsInt($protocolIndex);
        $this->assertSame('file,pipe', $arguments[$protocolIndex + 1]);
        $this->assertNotContains('sh', $arguments);
        $this->assertNotContains('-c', $arguments);
    }

    public function test_subtitle_input_is_limited_to_local_file_protocols(): void
    {
        $arguments = (new FfmpegArguments)->subtitle(
            '/cache/source.mkv',
            2,
            '/cache/subtitle.vtt',
        );

        $protocolIndex = array_search('-protocol_whitelist', $arguments, true);
        $this->assertIsInt($protocolIndex);
        $this->assertSame('file,pipe', $arguments[$protocolIndex + 1]);
        $this->assertContains('0:s:2', $arguments);
        $this->assertContains('-max_alloc', $arguments);
        $this->assertContains('-max_pixels', $arguments);
        $this->assertContains('-threads', $arguments);
    }
}
