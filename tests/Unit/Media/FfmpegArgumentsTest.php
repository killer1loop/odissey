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
        $this->assertContains('event', $arguments);
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

    public function test_hls_allows_http_only_for_the_signed_loopback_source_shape(): void
    {
        $arguments = (new FfmpegArguments)->hls(
            'http://127.0.0.1:8000/_internal/media/transcodes/01kyj20qfzxk7ke5050q2mxas5/source?expires=1&signature=test',
            '/cache/index.m3u8',
            '/cache/segment-%05d.ts',
        );
        $protocolIndex = array_search(
            '-protocol_whitelist',
            $arguments,
            true,
        );

        $this->assertIsInt($protocolIndex);
        $this->assertSame(
            'file,pipe,http,tcp',
            $arguments[$protocolIndex + 1],
        );

        $untrusted = (new FfmpegArguments)->hls(
            'https://media.example.test/video.mkv',
            '/cache/index.m3u8',
            '/cache/segment-%05d.ts',
        );
        $untrustedProtocolIndex = array_search(
            '-protocol_whitelist',
            $untrusted,
            true,
        );

        $this->assertSame(
            'file,pipe',
            $untrusted[$untrustedProtocolIndex + 1],
        );
    }

    public function test_hls_limits_sequential_remote_stdin_to_pipe_protocol(): void
    {
        $arguments = (new FfmpegArguments)->hls(
            'pipe:0',
            '/cache/index.m3u8',
            '/cache/segment-%05d.ts',
        );
        $protocolIndex = array_search(
            '-protocol_whitelist',
            $arguments,
            true,
        );

        $this->assertIsInt($protocolIndex);
        $this->assertSame('file,pipe', $arguments[$protocolIndex + 1]);
        $this->assertNotContains('-read_ahead_limit', $arguments);
        $this->assertNotContains('cache:pipe:0', $arguments);
        $this->assertContains('pipe:0', $arguments);
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

    public function test_remux_copies_video_and_audio_into_fragmented_mp4_hls(): void
    {
        $arguments = (new FfmpegArguments)->hls(
            '/media/compatible.mkv',
            '/cache/index.m3u8',
            '/cache/segment-%05d.m4s',
            deliveryMode: 'remux',
        );

        $videoIndex = array_search('-c:v', $arguments, true);
        $audioIndex = array_search('-c:a', $arguments, true);
        $this->assertSame('copy', $arguments[$videoIndex + 1]);
        $this->assertSame('copy', $arguments[$audioIndex + 1]);
        $this->assertContains('fmp4', $arguments);
        $this->assertContains('init.mp4', $arguments);
        $this->assertNotContains('libx264', $arguments);
    }

    public function test_audio_transcode_copies_video_and_converts_only_audio(): void
    {
        $arguments = (new FfmpegArguments)->hls(
            '/media/dts.mkv',
            '/cache/index.m3u8',
            '/cache/segment-%05d.m4s',
            deliveryMode: 'audioTranscode',
        );

        $videoIndex = array_search('-c:v', $arguments, true);
        $audioIndex = array_search('-c:a', $arguments, true);
        $this->assertSame('copy', $arguments[$videoIndex + 1]);
        $this->assertSame('aac', $arguments[$audioIndex + 1]);
        $this->assertNotContains('libx264', $arguments);
        $this->assertNotContains('-vf', $arguments);
    }
}
