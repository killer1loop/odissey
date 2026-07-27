<?php

namespace App\Services\Media;

use App\Services\Media\Exceptions\TranscodeQuotaExceeded;
use InvalidArgumentException;
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
     * Stream a validated source into FFmpeg without exposing its URL in the
     * process arguments or buffering the full object on disk.
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
        if ($input !== null) {
            $process->setInput($input);
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
    }

    /**
     * @param  list<string>  $arguments
     */
    protected function makeProcess(array $arguments, int $timeoutSeconds): Process
    {
        return $this->processes->make($arguments, $timeoutSeconds);
    }
}
