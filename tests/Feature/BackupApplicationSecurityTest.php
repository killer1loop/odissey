<?php

namespace Tests\Feature;

use App\Services\Backup\ApplicationBackupCompatibility;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Tests\TestCase;
use ZipArchive;

class BackupApplicationSecurityTest extends TestCase
{
    public function test_backup_refuses_a_stale_file_backed_key(): void
    {
        $directory = sys_get_temp_dir().'/odissey-backup-key-'.bin2hex(random_bytes(8));
        File::ensureDirectoryExists($directory);
        $databasePath = $directory.'/database.sqlite';
        $destination = $directory.'/backup.zip';
        $activeKey = 'base64:'.base64_encode(random_bytes(32));
        $staleKey = 'base64:'.base64_encode(random_bytes(32));
        $database = new \SQLite3($databasePath);
        $database->exec('CREATE TABLE example (value TEXT)');
        $database->close();
        File::put($directory.'/app.key', $staleKey."\n");

        $previousDatabase = config('database.connections.sqlite.database');
        $previousDataPath = config('odissey.data_path');
        config([
            'app.key' => $activeKey,
            'app.previous_keys' => [],
            'database.connections.sqlite.database' => $databasePath,
            'odissey.data_path' => $directory,
        ]);
        DB::purge('sqlite');

        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage(
                'The file-backed application key does not match the active APP_KEY.',
            );

            $this->artisan('odissey:backup', [
                'path' => $destination,
            ])->run();
        } finally {
            $this->assertFileDoesNotExist($destination);
            DB::purge('sqlite');
            config([
                'database.connections.sqlite.database' => $previousDatabase,
                'odissey.data_path' => $previousDataPath,
            ]);
            File::deleteDirectory($directory);
        }
    }

    public function test_backup_refuses_to_drop_previous_encryption_keys(): void
    {
        $directory = sys_get_temp_dir().'/odissey-backup-previous-'.bin2hex(random_bytes(8));
        File::ensureDirectoryExists($directory);
        $previousKeys = config('app.previous_keys');
        config([
            'app.previous_keys' => [
                'base64:'.base64_encode(random_bytes(32)),
            ],
        ]);

        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage(
                'Backup refused while APP_PREVIOUS_KEYS is configured',
            );

            $this->artisan('odissey:backup', [
                'path' => $directory.'/backup.zip',
            ])->run();
        } finally {
            config(['app.previous_keys' => $previousKeys]);
            $this->assertFileDoesNotExist($directory.'/backup.zip');
            File::deleteDirectory($directory);
        }
    }

    public function test_backup_rejects_relative_public_and_symlink_destinations(): void
    {
        $directory = sys_get_temp_dir().'/odissey-backup-path-'.bin2hex(random_bytes(8));
        File::ensureDirectoryExists($directory);
        $target = $directory.'/target.zip';
        File::put($target, 'existing');
        $symlink = $directory.'/symlink.zip';
        $this->assertTrue(symlink($target, $symlink));

        try {
            foreach ([
                'relative-backup.zip',
                base_path('odissey-review-backup.zip'),
                public_path('odissey-review-backup.zip'),
                $symlink,
            ] as $destination) {
                try {
                    $this->artisan('odissey:backup', [
                        'path' => $destination,
                    ])->run();
                    $this->fail("Unsafe backup destination was accepted: {$destination}");
                } catch (RuntimeException $exception) {
                    $this->assertStringContainsString(
                        'Backup destination',
                        $exception->getMessage(),
                    );
                }
            }

            $this->assertSame('existing', File::get($target));
            $this->assertFileDoesNotExist(base_path('odissey-review-backup.zip'));
            $this->assertFileDoesNotExist(public_path('odissey-review-backup.zip'));
        } finally {
            @unlink($symlink);
            File::deleteDirectory($directory);
            @unlink(base_path('odissey-review-backup.zip'));
            @unlink(public_path('odissey-review-backup.zip'));
        }
    }

    public function test_backup_records_compatible_application_schema_and_private_permissions(): void
    {
        $directory = sys_get_temp_dir().'/odissey-backup-manifest-'.bin2hex(random_bytes(8));
        File::ensureDirectoryExists($directory);
        $databasePath = $directory.'/database.sqlite';
        $destination = $directory.'/backup.zip';
        $key = 'base64:'.base64_encode(random_bytes(32));
        $migrations = $this->createCompatibleDatabase($databasePath);
        File::put($directory.'/app.key', $key."\n");
        $previousDatabase = config('database.connections.sqlite.database');
        $previousDataPath = config('odissey.data_path');
        $previousKey = config('app.key');
        $previousKeys = config('app.previous_keys');
        $previousRelease = config('odissey.release');
        config([
            'app.key' => $key,
            'app.previous_keys' => [],
            'database.connections.sqlite.database' => $databasePath,
            'odissey.data_path' => $directory,
            'odissey.release' => 'test-release',
        ]);
        DB::purge('sqlite');

        try {
            $this->artisan('odissey:backup', [
                'path' => $destination,
            ])->assertSuccessful();

            $this->assertSame(0600, fileperms($destination) & 0777);
            $zip = new ZipArchive;
            $this->assertTrue($zip->open($destination));
            $manifest = json_decode(
                $zip->getFromName('manifest.json') ?: '',
                true,
                flags: JSON_THROW_ON_ERROR,
            );
            $zip->close();

            $this->assertSame(
                ApplicationBackupCompatibility::FORMAT,
                $manifest['format'],
            );
            $this->assertSame(
                ApplicationBackupCompatibility::APPLICATION,
                $manifest['application'],
            );
            $this->assertSame('test-release', $manifest['application_version']);
            $this->assertSame($migrations, $manifest['migrations']);
            $this->assertSame(
                hash('sha256', implode("\n", $migrations)),
                $manifest['schema_sha256'],
            );
        } finally {
            DB::purge('sqlite');
            config([
                'app.key' => $previousKey,
                'app.previous_keys' => $previousKeys,
                'database.connections.sqlite.database' => $previousDatabase,
                'odissey.data_path' => $previousDataPath,
                'odissey.release' => $previousRelease,
            ]);
            File::deleteDirectory($directory);
        }
    }

    /**
     * @return list<string>
     */
    private function createCompatibleDatabase(string $path): array
    {
        $paths = glob(database_path('migrations/*.php'));
        $this->assertIsArray($paths);
        $migrations = array_map(
            static fn (string $migration): string => pathinfo(
                $migration,
                PATHINFO_FILENAME,
            ),
            $paths,
        );
        sort($migrations, SORT_STRING);

        $database = new \SQLite3($path);
        $database->exec(
            'CREATE TABLE migrations ('
            .'id INTEGER PRIMARY KEY AUTOINCREMENT, migration TEXT NOT NULL, batch INTEGER NOT NULL'
            .')',
        );
        $database->exec('CREATE TABLE example (value TEXT)');
        $statement = $database->prepare(
            'INSERT INTO migrations (migration, batch) VALUES (:migration, 1)',
        );
        $this->assertNotFalse($statement);
        foreach ($migrations as $migration) {
            $statement->bindValue(':migration', $migration, SQLITE3_TEXT);
            $result = $statement->execute();
            $this->assertNotFalse($result);
            $result->finalize();
            $statement->reset();
        }
        $database->close();

        return array_values($migrations);
    }
}
