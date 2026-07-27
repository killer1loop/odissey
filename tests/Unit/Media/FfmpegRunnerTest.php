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
        $this->assertTrue($runner->process?->isOutputDisabled());
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

    public function test_validated_source_streams_can_be_piped_to_ffmpeg_stdin(): void
    {
        $input = fopen('php://temp', 'w+');
        fwrite($input, 'streamed media bytes');
        rewind($input);

        try {
            (new FfmpegRunner)->runWithInput(
                [
                    PHP_BINARY,
                    '-r',
                    'exit(stream_get_contents(STDIN) === "streamed media bytes" ? 0 : 1);',
                ],
                5,
                $input,
            );
        } finally {
            fclose($input);
        }

        $this->addToAssertionCount(1);
    }

    public function test_child_processes_do_not_inherit_application_secrets_or_proxies(): void
    {
        $previousKey = getenv('APP_KEY');
        $previousKeys = getenv('APP_PREVIOUS_KEYS');
        $previousProxy = getenv('HTTPS_PROXY');
        $previousCredential = getenv('SYNTHETIC_CREDENTIALS');
        $previousEnvironmentSecret = $_ENV['SYNTHETIC_ENV_SECRET'] ?? null;
        putenv('APP_KEY=base64:synthetic-secret');
        putenv('APP_PREVIOUS_KEYS=base64:old-synthetic-secret');
        putenv('HTTPS_PROXY=http://proxy.invalid');
        putenv('SYNTHETIC_CREDENTIALS=credential-material');
        $_ENV['SYNTHETIC_ENV_SECRET'] = 'environment-secret';

        try {
            (new FfmpegRunner)->run([
                PHP_BINARY,
                '-r',
                'exit('
                    .'getenv("APP_KEY") === false'
                    .' && getenv("APP_PREVIOUS_KEYS") === false'
                    .' && getenv("HTTPS_PROXY") === false'
                    .' && getenv("SYNTHETIC_CREDENTIALS") === false'
                    .' && getenv("SYNTHETIC_ENV_SECRET") === false'
                    .' && getenv("HOME") === "/tmp"'
                    .' ? 0 : 1);',
            ], 5);
        } finally {
            $previousKey === false
                ? putenv('APP_KEY')
                : putenv('APP_KEY='.$previousKey);
            $previousKeys === false
                ? putenv('APP_PREVIOUS_KEYS')
                : putenv('APP_PREVIOUS_KEYS='.$previousKeys);
            $previousProxy === false
                ? putenv('HTTPS_PROXY')
                : putenv('HTTPS_PROXY='.$previousProxy);
            $previousCredential === false
                ? putenv('SYNTHETIC_CREDENTIALS')
                : putenv('SYNTHETIC_CREDENTIALS='.$previousCredential);
            if ($previousEnvironmentSecret === null) {
                unset($_ENV['SYNTHETIC_ENV_SECRET']);
            } else {
                $_ENV['SYNTHETIC_ENV_SECRET'] = $previousEnvironmentSecret;
            }
        }

        $this->addToAssertionCount(1);
    }
}
