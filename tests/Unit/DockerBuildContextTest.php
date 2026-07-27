<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

class DockerBuildContextTest extends TestCase
{
    public function test_generated_laravel_state_is_excluded_from_the_build_context(): void
    {
        $ignore = file_get_contents(dirname(__DIR__, 2).'/.dockerignore');

        $this->assertIsString($ignore);
        $this->assertStringContainsString('bootstrap/cache/**', $ignore);
        $this->assertStringContainsString('storage/**', $ignore);
        $this->assertStringContainsString('database/*.sqlite-*', $ignore);
    }

    public function test_runtime_and_vendor_stages_use_selective_source_copies(): void
    {
        $dockerfile = file_get_contents(dirname(__DIR__, 2).'/Dockerfile');
        $this->assertStringContainsString('CACHE_STORE=file', $dockerfile);

        $this->assertIsString($dockerfile);
        $this->assertSame(3, preg_match_all(
            '/^FROM\\s+[^\\s]+@sha256:[a-f0-9]{64}(?:\\s+AS\\s+\\w+)?$/m',
            $dockerfile,
        ));
        $this->assertDoesNotMatchRegularExpression(
            '/^COPY(?:\\s+--[^\\s]+)*\\s+\\.\\s+\\.\\s*$/m',
            $dockerfile,
        );
        $this->assertStringContainsString(
            'COPY --chown=www-data:www-data app ./app',
            $dockerfile,
        );
        $this->assertStringContainsString(
            'COPY --chown=www-data:www-data config ./config',
            $dockerfile,
        );
        $this->assertStringContainsString(
            'COPY --chown=www-data:www-data composer.json composer.lock package-lock.json ./',
            $dockerfile,
        );
        $this->assertStringContainsString('USER www-data:www-data', $dockerfile);
        $this->assertSame(
            2,
            substr_count(
                $dockerfile,
                "        build \\\n        bootstrap/cache",
            ),
        );

        $supervisor = file_get_contents(
            dirname(__DIR__, 2).'/docker/supervisord.conf',
        );
        $this->assertIsString($supervisor);
        $this->assertStringNotContainsString('user=root', $supervisor);
        $this->assertStringContainsString(
            '[program:queue-media-discovery]',
            $supervisor,
        );
        $this->assertStringContainsString(
            'memory_limit=256M artisan queue:work --queue=media-discovery',
            $supervisor,
        );
        $this->assertStringContainsString(
            '[program:queue-media-scan]',
            $supervisor,
        );
        $this->assertStringContainsString(
            "numprocs=2\ncommand=php -d memory_limit=384M artisan queue:work --queue=media-scan",
            $supervisor,
        );
        $this->assertStringContainsString(
            '[program:queue-media-enrichment]',
            $supervisor,
        );
        $this->assertStringContainsString(
            "numprocs=2\ncommand=php -d memory_limit=256M artisan queue:work --queue=media-enrichment",
            $supervisor,
        );
        $this->assertStringContainsString(
            '[program:queue-iptv-vod]',
            $supervisor,
        );
        $this->assertStringContainsString(
            "numprocs=4\ncommand=php -d memory_limit=256M artisan queue:work --queue=iptv-vod",
            $supervisor,
        );
        $healthcheck = file_get_contents(
            dirname(__DIR__, 2).'/docker/healthcheck.sh',
        );
        $this->assertIsString($healthcheck);
        $this->assertStringContainsString(
            '[ "${running_processes}" -eq 14 ]',
            $healthcheck,
        );
    }

    public function test_compose_requires_safe_first_launch_and_runtime_limits(): void
    {
        $compose = file_get_contents(dirname(__DIR__, 2).'/compose.yaml');

        $this->assertIsString($compose);
        $this->assertStringContainsString(
            'ODISSEY_SETUP_TOKEN: "${ODISSEY_SETUP_TOKEN:?',
            $compose,
        );
        $this->assertStringContainsString('no-new-privileges:true', $compose);
        $this->assertStringContainsString("cap_drop:\n      - ALL", $compose);
        $this->assertStringContainsString('pids_limit:', $compose);
        $this->assertStringContainsString('mem_limit:', $compose);
        $this->assertStringContainsString('cpus:', $compose);
    }

    public function test_entrypoint_fails_closed_for_interrupted_restore_or_missing_key(): void
    {
        $entrypoint = file_get_contents(
            dirname(__DIR__, 2).'/docker/entrypoint.sh',
        );

        $this->assertIsString($entrypoint);
        $this->assertStringContainsString(
            'restore_marker="${database_path}.restore-in-progress"',
            $entrypoint,
        );
        $this->assertStringContainsString(
            'An interrupted Odissey restore was detected',
            $entrypoint,
        );
        $this->assertStringContainsString(
            'if [ "${database_had_data}" = true ]; then',
            $entrypoint,
        );
        $this->assertStringContainsString(
            'refusing to generate a replacement',
            $entrypoint,
        );
        $this->assertStringContainsString(
            'media:sources:scan --recover-interrupted --no-interaction',
            $entrypoint,
        );
        $this->assertStringContainsString(
            'iptv:catalog:refresh --recover-upgrade --no-interaction',
            $entrypoint,
        );
        $this->assertStringContainsString(
            'media:captions:prune-unconfigured --no-interaction',
            $entrypoint,
        );

        $database = file_get_contents(
            dirname(__DIR__, 2).'/config/database.php',
        );
        $this->assertIsString($database);
        $this->assertStringContainsString(
            "env('DB_TRANSACTION_MODE', 'IMMEDIATE')",
            $database,
        );

        $restore = file_get_contents(
            dirname(__DIR__, 2).'/app/Console/Commands/RestoreApplication.php',
        );
        $this->assertIsString($restore);
        $this->assertStringContainsString(
            'queue-media-discovery',
            $restore,
        );
        $this->assertStringContainsString(
            'queue-media-scan_00',
            $restore,
        );
        $this->assertStringContainsString(
            'queue-media-enrichment_01',
            $restore,
        );
        $this->assertStringContainsString(
            'queue-iptv-vod_03',
            $restore,
        );
    }

    public function test_entrypoint_process_stops_before_recovery_state_can_be_overwritten(): void
    {
        $root = sys_get_temp_dir().'/odissey-entrypoint-'.bin2hex(random_bytes(8));
        $data = $root.'/data';
        $transcodes = $root.'/transcodes';
        $database = $data.'/database.sqlite';
        $key = $data.'/app.key';
        $marker = $database.'.restore-in-progress';
        $entrypoint = dirname(__DIR__, 2).'/docker/entrypoint.sh';
        mkdir($data, 0700, true);
        file_put_contents($database, 'existing database bytes');
        file_put_contents($marker, 'interrupted');
        $environment = [
            'APP_KEY' => false,
            'DB_DATABASE' => $database,
            'ODISSEY_APP_KEY_FILE' => $key,
            'ODISSEY_DATA_PATH' => $data,
            'ODISSEY_TRANSCODE_PATH' => $transcodes,
        ];

        try {
            $process = new Process(
                ['/bin/sh', $entrypoint, 'true'],
                dirname(__DIR__, 2),
                $environment,
            );
            $process->run();
            $this->assertFalse($process->isSuccessful());
            $this->assertStringContainsString(
                'An interrupted Odissey restore was detected',
                $process->getErrorOutput(),
            );
            $this->assertFileDoesNotExist($key);

            unlink($marker);
            $process = new Process(
                ['/bin/sh', $entrypoint, 'true'],
                dirname(__DIR__, 2),
                $environment,
            );
            $process->run();
            $this->assertFalse($process->isSuccessful());
            $this->assertStringContainsString(
                'refusing to generate a replacement',
                $process->getErrorOutput(),
            );
            $this->assertFileDoesNotExist($key);
            $this->assertSame(
                'existing database bytes',
                file_get_contents($database),
            );
        } finally {
            @unlink($marker);
            @unlink($key);
            @unlink($database);
            @rmdir($transcodes);
            @rmdir($data);
            @rmdir($root);
        }
    }
}
