<?php

namespace App\Services\Media;

use App\Services\Media\Exceptions\TranscodeQuotaExceeded;
use Generator;
use Illuminate\Support\Facades\File;
use InvalidArgumentException;
use Psr\Http\Message\StreamInterface;
use RuntimeException;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;

class FfmpegRunner
{
    public function __construct(
        private readonly MediaProcessFactory $processes = new MediaProcessFactory,
    ) {}

    /**
     * Run an executable with an argument vector. No shell is involved.
     *
     * @param  list<string>  $arguments
     * @param  (callable(): bool)|null  $shouldContinue
     */
    public function run(
        array $arguments,
        int $timeoutSeconds,
        ?callable $shouldContinue = null,
    ): void {
        $this->execute($arguments, $timeoutSeconds, $shouldContinue);
    }

    /**
     * Stream a validated, bounded source into FFmpeg without exposing its URL
     * in the process arguments. FFmpeg may use its local cache protocol when
     * the input container requires seeking.
     *
     * @param  list<string>  $arguments
     * @param  (callable(): bool)|null  $shouldContinue
     */
    public function runWithInput(
        array $arguments,
        int $timeoutSeconds,
        mixed $input,
        ?callable $shouldContinue = null,
    ): void {
        $this->execute(
            $arguments,
            $timeoutSeconds,
            $shouldContinue,
            $input,
        );
    }

    /**
     * @param  list<string>  $arguments
     * @param  (callable(): bool)|null  $shouldContinue
     */
    private function execute(
        array $arguments,
        int $timeoutSeconds,
        ?callable $shouldContinue = null,
        mixed $input = null,
    ): void {
        if ($arguments === [] || ! array_is_list($arguments)) {
            throw new InvalidArgumentException('A non-empty argument vector is required.');
        }

        foreach ($arguments as $argument) {
            if (! is_string($argument)) {
                throw new InvalidArgumentException('Every process argument must be a string.');
            }
        }

        $process = $this->makeProcess($arguments, $timeoutSeconds);
        $cacheDirectory = null;

        try {
            if ($input !== null) {
                $cacheDirectory = $this->prepareCacheDirectory($arguments);
                if ($cacheDirectory !== null) {
                    $process->setEnv(array_merge($process->getEnv(), [
                        'TMPDIR' => $cacheDirectory,
                        'TMP' => $cacheDirectory,
                        'TEMP' => $cacheDirectory,
                    ]));
                }
                $process->setInput(
                    $input instanceof StreamInterface
                        ? $this->streamChunks($input)
                        : $input,
                );
            }

            if ($shouldContinue === null) {
                $process->mustRun();

                return;
            }

            $process->start();

            while ($process->isRunning()) {
                $process->checkTimeout();

                if (! $shouldContinue()) {
                    $process->stop(0);

                    throw new TranscodeQuotaExceeded;
                }

                usleep(250_000);
            }

            if (! $process->isSuccessful()) {
                throw new ProcessFailedException($process);
            }
        } finally {
            if (
                $cacheDirectory !== null
                && File::isDirectory($cacheDirectory)
                && ! File::deleteDirectory($cacheDirectory)
            ) {
                throw new RuntimeException(
                    'The FFmpeg input cache could not be removed.',
                );
            }
        }
    }

    /**
     * Keep FFmpeg's seek cache inside the active session directory so the
     * existing quota watchdog and session cleanup account for every byte.
     *
     * @param  list<string>  $arguments
     */
    private function prepareCacheDirectory(array $arguments): ?string
    {
        if (! in_array('cache:pipe:0', $arguments, true)) {
            return null;
        }

        $manifestPath = $arguments[array_key_last($arguments)];
        $sessionDirectory = dirname($manifestPath);
        if (
            ! str_starts_with($sessionDirectory, DIRECTORY_SEPARATOR)
            || ! File::isDirectory($sessionDirectory)
            || is_link($sessionDirectory)
        ) {
            throw new InvalidArgumentException(
                'The FFmpeg cache requires a safe session directory.',
            );
        }

        $cacheDirectory = $sessionDirectory
            .DIRECTORY_SEPARATOR
            .'.ffmpeg-cache-'.bin2hex(random_bytes(8));
        File::ensureDirectoryExists($cacheDirectory, 0700, true);
        File::chmod($cacheDirectory, 0700);

        if (is_link($cacheDirectory)) {
            throw new RuntimeException('The FFmpeg input cache is unsafe.');
        }

        return $cacheDirectory;
    }

    /**
     * Symfony Process consumes iterators only when its stdin pipe is writable.
     * This keeps remote reads back-pressured by FFmpeg and avoids adapting a
     * PSR stream through PHP's fragile custom stream-wrapper bridge.
     *
     * @return Generator<int, string>
     */
    private function streamChunks(StreamInterface $stream): Generator
    {
        while (! $stream->eof()) {
            $chunk = $stream->read(64 * 1024);

            if ($chunk === '') {
                usleep(10_000);

                continue;
            }

            yield $chunk;
        }
    }

    /**
     * @param  list<string>  $arguments
     */
    protected function makeProcess(array $arguments, int $timeoutSeconds): Process
    {
        return $this->processes->make($arguments, $timeoutSeconds);
    }
}
