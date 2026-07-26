<?php

namespace App\Services\Media;

use Symfony\Component\Process\Process;

class MediaProcessFactory
{
    /**
     * @param  list<string>  $arguments
     */
    public function make(array $arguments, int $timeoutSeconds): Process
    {
        $process = new Process(
            $arguments,
            env: $this->sanitizedEnvironment(),
            timeout: $timeoutSeconds,
        );
        $process->disableOutput();

        return $process;
    }

    /**
     * Prevent media parsers and codecs from inheriting application secrets or
     * outbound proxy configuration from the Laravel process.
     *
     * @return array<string, string|false>
     */
    private function sanitizedEnvironment(): array
    {
        $environment = [];
        $allowed = [
            'LANG',
            'LC_ALL',
            'LC_CTYPE',
            'PATH',
            'TZ',
        ];

        $inherited = array_merge(
            getenv() ?: [],
            $_SERVER,
            $_ENV,
        );

        foreach ($inherited as $name => $value) {
            if (! is_string($name)) {
                continue;
            }

            $environment[$name] = in_array($name, $allowed, true)
                && is_string($value)
                ? $value
                : false;
        }

        return array_merge($environment, [
            'HOME' => '/tmp',
            'TMPDIR' => '/tmp',
            'TMP' => '/tmp',
            'TEMP' => '/tmp',
        ]);
    }
}
