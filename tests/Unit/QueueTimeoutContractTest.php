<?php

namespace Tests\Unit;

use App\Jobs\Media\ProcessMediaSourceObject;
use App\Jobs\Media\ScanMediaSource;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Tests\TestCase;

class QueueTimeoutContractTest extends TestCase
{
    public function test_media_queue_retry_windows_outlive_workers_jobs_and_locks(): void
    {
        $root = dirname(__DIR__, 2);
        $supervisor = file_get_contents($root.'/docker/supervisord.conf');
        $dockerfile = file_get_contents($root.'/Dockerfile');
        $environment = file_get_contents($root.'/.env.example');
        $this->assertIsString($supervisor);
        $this->assertIsString($dockerfile);
        $this->assertIsString($environment);

        $discovery = new ScanMediaSource(
            '01J00000000000000000000000',
            '01J00000000000000000000001',
        );
        $overlap = $discovery->middleware()[0];
        $this->assertInstanceOf(WithoutOverlapping::class, $overlap);
        $discoveryRetry = (int) config(
            'queue.connections.database-media-discovery.retry_after',
        );
        $discoveryWorkerTimeout = $this->workerTimeout(
            $supervisor,
            'queue-media-discovery',
        );

        $this->assertGreaterThan($discovery->timeout, $discoveryRetry);
        $this->assertGreaterThan($discoveryWorkerTimeout, $discoveryRetry);
        $this->assertGreaterThan($overlap->expiresAfter, $discoveryRetry);
        $this->assertSame(
            $discoveryRetry,
            $this->environmentValue(
                $dockerfile,
                'DB_MEDIA_DISCOVERY_QUEUE_RETRY_AFTER',
            ),
        );
        $this->assertSame(
            $discoveryRetry,
            $this->environmentValue(
                $environment,
                'DB_MEDIA_DISCOVERY_QUEUE_RETRY_AFTER',
            ),
        );

        $scan = new ProcessMediaSourceObject(
            '01J00000000000000000000000',
            '01J00000000000000000000001',
            'movie.mp4',
            'movie.mp4',
            1,
            null,
            null,
        );
        $scanRetry = (int) config(
            'queue.connections.database-media-scan.retry_after',
        );

        $this->assertGreaterThan($scan->timeout, $scanRetry);
        $this->assertGreaterThan(
            $this->workerTimeout($supervisor, 'queue-media-scan'),
            $scanRetry,
        );
    }

    private function workerTimeout(string $supervisor, string $program): int
    {
        $matched = preg_match(
            '/\\[program:'.preg_quote($program, '/')
                .'\\](.*?)(?=\\R\\[|\\z)/s',
            $supervisor,
            $section,
        );
        $this->assertSame(1, $matched);
        $matched = preg_match(
            '/^command=[^\\r\\n]*--timeout=(\\d+)/m',
            $section[1],
            $matches,
        );
        $this->assertSame(1, $matched);

        return (int) $matches[1];
    }

    private function environmentValue(string $contents, string $name): int
    {
        $matched = preg_match(
            '/(?:^|\\s)'.preg_quote($name, '/').'=(\\d+)/m',
            $contents,
            $matches,
        );
        $this->assertSame(1, $matched);

        return (int) $matches[1];
    }
}
