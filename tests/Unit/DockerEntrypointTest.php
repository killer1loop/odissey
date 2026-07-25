<?php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

class DockerEntrypointTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function unsafeManagedPaths(): array
    {
        return [
            'data root' => ['ODISSEY_DATA_PATH', '/'],
            'data app root' => ['ODISSEY_DATA_PATH', '/app'],
            'data var root' => ['ODISSEY_DATA_PATH', '/var'],
            'data parent segment' => ['ODISSEY_DATA_PATH', '/var/lib/../'],
            'data repeated slash' => ['ODISSEY_DATA_PATH', '//var/lib/odissey'],
            'transcode root' => ['ODISSEY_TRANSCODE_PATH', '/'],
            'transcode app root' => ['ODISSEY_TRANSCODE_PATH', '/app'],
            'transcode var root' => ['ODISSEY_TRANSCODE_PATH', '/var'],
            'transcode parent segment' => ['ODISSEY_TRANSCODE_PATH', '/var/cache/../'],
            'transcode repeated slash' => ['ODISSEY_TRANSCODE_PATH', '//var/cache/odissey'],
        ];
    }

    #[DataProvider('unsafeManagedPaths')]
    public function test_recursive_managed_paths_fail_before_entrypoint_mutation(
        string $variable,
        string $unsafePath,
    ): void {
        $environment = [
            'ODISSEY_DATA_PATH' => '/private/tmp/odissey-entrypoint-data',
            'ODISSEY_TRANSCODE_PATH' => '/private/tmp/odissey-entrypoint-transcodes',
            $variable => $unsafePath,
        ];
        $process = new Process(
            ['sh', dirname(__DIR__, 2).'/docker/entrypoint.sh', '/usr/bin/true'],
            env: $environment,
        );

        $process->run();

        $this->assertSame(1, $process->getExitCode());
        $this->assertStringContainsString(
            "{$variable} must",
            $process->getErrorOutput(),
        );
    }
}
