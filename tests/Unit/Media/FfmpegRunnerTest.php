<?php

namespace Tests\Unit\Media;

use App\Services\Media\Exceptions\TranscodeQuotaExceeded;
use App\Services\Media\FfmpegRunner;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

class FfmpegRunnerTest extends TestCase
{
    public function test_quiet_processes_keep_the_total_timeout_without_an_idle_timeout(): void
    {
        $runner = new class extends FfmpegRunner
        {
            public ?Process $process = null;

            protected function makeProcess(array $arguments, int $timeoutSeconds): Process
            {
                return $this->process = parent::makeProcess($arguments, $timeoutSeconds);
            }
        };

        $runner->run([PHP_BINARY, '-r', ''], 280);

        $this->assertSame(280.0, $runner->process?->getTimeout());
        $this->assertNull($runner->process?->getIdleTimeout());
    }

    public function test_watchdog_stops_a_process_when_its_resource_guard_fails(): void
    {
        $checks = 0;

        $this->expectException(TranscodeQuotaExceeded::class);

        (new FfmpegRunner)->run(
            [PHP_BINARY, '-r', 'usleep(1000000);'],
            5,
            function () use (&$checks): bool {
                $checks++;

                return $checks < 2;
            },
        );
    }
}
