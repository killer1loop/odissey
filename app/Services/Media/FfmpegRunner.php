<?php

namespace App\Services\Media;

use App\Services\Media\Exceptions\TranscodeQuotaExceeded;
use Generator;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Psr\Http\Message\StreamInterface;
use RuntimeException;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;
use Throwable;

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
     * in the process arguments. Only containers that FFmpeg can consume
     * sequentially are sent through this back-pressured pipe.
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
        $tail = new FfmpegErrorTail($this->errorTailBytes());
        $collectError = function (string $type, string $data) use ($tail): void {
            if ($type === Process::ERR) {
                $tail->append($data);
            }
        };

        try {
            if ($input !== null) {
                $process->setInput(
                    $input instanceof StreamInterface
                        ? $this->streamChunks($input)
                        : $input,
                );
            }

            if ($shouldContinue === null) {
                // Output stays disabled inside Symfony; the callback still
                // receives chunks, so nothing accumulates internally.
                $process->run($collectError);
            } else {
                $process->start($collectError);

                while ($process->isRunning()) {
                    $process->checkTimeout();

                    if (! $shouldContinue()) {
                        Log::warning('FFmpeg process stopped by resource guard.', [
                            'stderr_tail' => $tail->tail(),
                        ]);
                        $process->stop(0);

                        throw new TranscodeQuotaExceeded;
                    }

                    usleep(250_000);
                }
            }
        } catch (ProcessTimedOutException $exception) {
            Log::warning('FFmpeg process timed out.', [
                'stderr_tail' => $tail->tail(),
            ]);

            throw $exception;
        } catch (Throwable $exception) {
            if (! $exception instanceof TranscodeQuotaExceeded) {
                Log::warning('FFmpeg process aborted.', [
                    'exception' => $exception::class,
                    'stderr_tail' => $tail->tail(),
                ]);
            }

            throw $exception;
        }

        if (! $process->isSuccessful()) {
            Log::warning('FFmpeg process failed.', [
                'exit_code' => $process->getExitCode(),
                'stderr_tail' => $tail->tail(),
            ]);

            throw new ProcessFailedException($process);
        }
    }

    private function errorTailBytes(): int
    {
        return min(
            65536,
            max(
                1024,
                (int) config('odissey.ffmpeg_error_tail_bytes', 8192),
            ),
        );
    }

    /**
     * Symfony Process consumes iterators only when its stdin pipe is writable.
     * This keeps remote reads back-pressured by FFmpeg and avoids adapting a
     * PSR stream through PHP's fragile custom stream-wrapper bridge.
     *
     * A silent upstream must fail the job instead of holding the single
     * conversion slot until the total timeout; the deadline resets on every
     * successful read.
     *
     * @return Generator<int, string>
     */
    private function streamChunks(StreamInterface $stream): Generator
    {
        $stallSeconds = max(
            1,
            (int) config('odissey.transcode_source_stall_seconds', 60),
        );
        $deadlineNanoseconds = hrtime(true) + $stallSeconds * 1_000_000_000;

        while (! $stream->eof()) {
            $chunk = $stream->read(64 * 1024);

            if ($chunk === '') {
                if (hrtime(true) >= $deadlineNanoseconds) {
                    throw new RuntimeException('source_read_failed');
                }

                usleep(10_000);

                continue;
            }

            $deadlineNanoseconds = hrtime(true) + $stallSeconds * 1_000_000_000;

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
