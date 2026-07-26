<?php

namespace Tests\Unit\Media;

use App\Services\Media\MediaProbe;
use App\Services\Media\MediaProcessFactory;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class MediaProbeTest extends TestCase
{
    public function test_probe_output_is_bounded_without_process_level_accumulation(): void
    {
        config(['odissey.ffprobe_max_output_bytes' => 1024]);
        $factory = new class extends MediaProcessFactory
        {
            public ?Process $process = null;

            public function make(
                array $arguments,
                int $timeoutSeconds,
            ): Process {
                return $this->process = parent::make([
                    PHP_BINARY,
                    '-r',
                    'fwrite(STDOUT, str_repeat("x", 2048));',
                ], $timeoutSeconds);
            }
        };

        $result = (new MediaProbe($factory))->inspect(
            '/synthetic/movie.mp4',
            'movie.mp4',
        );

        $this->assertSame('video', $result['media_kind']);
        $this->assertSame('mp4', $result['container']);
        $this->assertFalse($result['requires_transcode']);
        $this->assertTrue($factory->process?->isOutputDisabled());
    }
}
