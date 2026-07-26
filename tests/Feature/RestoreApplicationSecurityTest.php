<?php

namespace Tests\Feature;

use App\Services\Backup\ApplicationBackupCompatibility;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Tests\TestCase;
use ZipArchive;

class RestoreApplicationSecurityTest extends TestCase
{
    public function test_restore_rejects_database_entries_larger_than_the_configured_limit_before_decompression(): void
    {
        $path = sys_get_temp_dir().'/odissey-restore-bomb-'.bin2hex(random_bytes(8)).'.zip';
        $database = str_repeat("\0", 2 * 1024 * 1024);
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE));
        $zip->addFromString('database.sqlite', $database);
        $zip->addFromString(
            'app.key',
            'base64:'.base64_encode(random_bytes(32)),
        );
        $zip->addFromString('manifest.json', json_encode([
            'format' => ApplicationBackupCompatibility::FORMAT,
            'application' => ApplicationBackupCompatibility::APPLICATION,
            'application_version' => 'test',
            'database_sha256' => hash('sha256', $database),
            'migrations' => $this->availableMigrations(),
            'schema_sha256' => hash(
                'sha256',
                implode("\n", $this->availableMigrations()),
            ),
        ], JSON_THROW_ON_ERROR));
        $zip->close();
        config(['odissey.backup_max_database_bytes' => 1024 * 1024]);

        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('Backup entry is missing or exceeds safety limits.');

            $this->artisan('odissey:restore', [
                'path' => $path,
                '--force' => true,
                '--offline' => true,
            ])->run();
        } finally {
            @unlink($path);
        }
    }

    public function test_restore_requires_an_explicit_offline_confirmation(): void
    {
        $this->artisan('odissey:restore', [
            'path' => '/does/not/matter.zip',
            '--force' => true,
        ])
            ->expectsOutputToContain('Restore requires --offline')
            ->assertFailed();
    }

    public function test_restore_rejects_archives_with_unexpected_entries(): void
    {
        $directory = sys_get_temp_dir().'/odissey-restore-extra-'.bin2hex(random_bytes(8));
        File::ensureDirectoryExists($directory);
        $databasePath = $directory.'/backup.sqlite';
        $archivePath = $directory.'/backup.zip';
        $database = new \SQLite3($databasePath);
        $database->exec('CREATE TABLE example (value TEXT)');
        $database->close();
        $this->createBackup(
            $archivePath,
            $databasePath,
            'base64:'.base64_encode(random_bytes(32)),
        );
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($archivePath));
        $this->assertTrue($zip->addFromString('unexpected.txt', 'ignored'));
        $this->assertTrue($zip->close());

        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('Backup contains unexpected entries.');

            $this->artisan('odissey:restore', [
                'path' => $archivePath,
                '--force' => true,
                '--offline' => true,
            ])->run();
        } finally {
            File::deleteDirectory($directory);
        }
    }

    public function test_restore_rejects_a_key_mismatch_when_app_key_is_external(): void
    {
        $directory = sys_get_temp_dir().'/odissey-restore-external-'.bin2hex(random_bytes(8));
        File::ensureDirectoryExists($directory);
        $databasePath = $directory.'/backup.sqlite';
        $archivePath = $directory.'/backup.zip';
        $database = new \SQLite3($databasePath);
        $database->exec('CREATE TABLE example (value TEXT)');
        $database->close();
        $activeKey = 'base64:'.base64_encode(random_bytes(32));
        $backupKey = 'base64:'.base64_encode(random_bytes(32));
        $this->createBackup($archivePath, $databasePath, $backupKey);
        $previousKey = getenv('APP_KEY');
        $previousSource = getenv('ODISSEY_APP_KEY_SOURCE');
        putenv('APP_KEY='.$activeKey);
        putenv('ODISSEY_APP_KEY_SOURCE=environment');
        config(['app.key' => $activeKey]);

        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('Backup key does not match externally managed APP_KEY');

            $this->artisan('odissey:restore', [
                'path' => $archivePath,
                '--force' => true,
                '--offline' => true,
            ])->run();
        } finally {
            $previousKey === false
                ? putenv('APP_KEY')
                : putenv('APP_KEY='.$previousKey);
            $previousSource === false
                ? putenv('ODISSEY_APP_KEY_SOURCE')
                : putenv('ODISSEY_APP_KEY_SOURCE='.$previousSource);
            File::deleteDirectory($directory);
        }
    }

    public function test_restore_rejects_a_backup_from_a_newer_schema(): void
    {
        $directory = sys_get_temp_dir().'/odissey-restore-compat-'.bin2hex(random_bytes(8));
        File::ensureDirectoryExists($directory);
        $databasePath = $directory.'/backup.sqlite';
        $archivePath = $directory.'/backup.zip';
        $database = new \SQLite3($databasePath);
        $database->exec('CREATE TABLE example (value TEXT)');
        $database->close();
        $migrations = [
            ...$this->availableMigrations(),
            '2099_01_01_000000_future_schema',
        ];
        $this->createBackup(
            $archivePath,
            $databasePath,
            'base64:'.base64_encode(random_bytes(32)),
            $migrations,
        );

        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage(
                'Backup schema is not compatible with this Odissey release.',
            );

            $this->artisan('odissey:restore', [
                'path' => $archivePath,
                '--force' => true,
                '--offline' => true,
            ])->run();
        } finally {
            File::deleteDirectory($directory);
        }
    }

    public function test_restore_rejects_a_backup_from_another_application(): void
    {
        $directory = sys_get_temp_dir().'/odissey-restore-app-'.bin2hex(random_bytes(8));
        File::ensureDirectoryExists($directory);
        $databasePath = $directory.'/backup.sqlite';
        $archivePath = $directory.'/backup.zip';
        $database = new \SQLite3($databasePath);
        $database->exec('CREATE TABLE example (value TEXT)');
        $database->close();
        $this->createBackup(
            $archivePath,
            $databasePath,
            'base64:'.base64_encode(random_bytes(32)),
            application: 'another-application',
        );

        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('Backup compatibility metadata is invalid.');

            $this->artisan('odissey:restore', [
                'path' => $archivePath,
                '--force' => true,
                '--offline' => true,
            ])->run();
        } finally {
            File::deleteDirectory($directory);
        }
    }

    public function test_restore_refuses_to_touch_live_files_when_a_recovery_marker_exists(): void
    {
        $directory = sys_get_temp_dir().'/odissey-restore-marker-'.bin2hex(random_bytes(8));
        File::ensureDirectoryExists($directory);
        $databasePath = $directory.'/database.sqlite';
        $backupDatabasePath = $directory.'/backup.sqlite';
        $keyPath = $directory.'/app.key';
        $archivePath = $directory.'/backup.zip';
        $oldKey = 'base64:'.base64_encode(random_bytes(32));
        $newKey = 'base64:'.base64_encode(random_bytes(32));

        $current = new \SQLite3($databasePath);
        $current->exec('CREATE TABLE state (value TEXT)');
        $current->exec("INSERT INTO state VALUES ('old')");
        $current->close();
        File::put($keyPath, $oldKey."\n");
        File::put($databasePath.'.restore-in-progress', "interrupted\n");

        $backup = new \SQLite3($backupDatabasePath);
        $backup->exec('CREATE TABLE state (value TEXT)');
        $backup->exec("INSERT INTO state VALUES ('new')");
        $backup->close();
        $this->createBackup($archivePath, $backupDatabasePath, $newKey);

        $previousDatabase = config('database.connections.sqlite.database');
        $previousDataPath = config('odissey.data_path');
        $previousSource = getenv('ODISSEY_APP_KEY_SOURCE');
        putenv('ODISSEY_APP_KEY_SOURCE=file');
        config([
            'app.key' => $oldKey,
            'database.connections.sqlite.database' => $databasePath,
            'odissey.data_path' => $directory,
        ]);

        try {
            try {
                $this->artisan('odissey:restore', [
                    'path' => $archivePath,
                    '--force' => true,
                    '--offline' => true,
                ])->run();
                $this->fail('Restore unexpectedly ignored the recovery marker.');
            } catch (RuntimeException $exception) {
                $this->assertStringContainsString(
                    'interrupted restore marker exists',
                    $exception->getMessage(),
                );
            }

            $live = new \SQLite3($databasePath, SQLITE3_OPEN_READONLY);
            $this->assertSame('old', $live->querySingle('SELECT value FROM state'));
            $live->close();
            $this->assertSame($oldKey, trim(File::get($keyPath)));
            $this->assertFileExists($databasePath.'.restore-in-progress');
            $this->assertFileDoesNotExist($databasePath.'.before-restore');
            $this->assertFileDoesNotExist($keyPath.'.before-restore');
        } finally {
            config([
                'database.connections.sqlite.database' => $previousDatabase,
                'odissey.data_path' => $previousDataPath,
            ]);
            $previousSource === false
                ? putenv('ODISSEY_APP_KEY_SOURCE')
                : putenv('ODISSEY_APP_KEY_SOURCE='.$previousSource);
            File::deleteDirectory($directory);
        }
    }

    public function test_offline_restore_swaps_a_valid_database_and_matching_key_pair(): void
    {
        $directory = sys_get_temp_dir().'/odissey-restore-pair-'.bin2hex(random_bytes(8));
        File::ensureDirectoryExists($directory);
        $databasePath = $directory.'/database.sqlite';
        $backupDatabasePath = $directory.'/backup.sqlite';
        $keyPath = $directory.'/app.key';
        $archivePath = $directory.'/backup.zip';
        $artworkPath = $directory.'/artwork';
        $captionPath = $directory.'/captions';
        $oldKey = 'base64:'.base64_encode(random_bytes(32));
        $newKey = 'base64:'.base64_encode(random_bytes(32));

        $current = new \SQLite3($databasePath);
        $current->exec('CREATE TABLE state (value TEXT)');
        $current->exec("INSERT INTO state VALUES ('old')");
        $current->close();
        File::put($keyPath, $oldKey."\n");
        File::ensureDirectoryExists($artworkPath);
        File::ensureDirectoryExists($captionPath);
        File::put($artworkPath.'/old.jpg', 'old artwork');
        File::put($captionPath.'/old.vtt', 'old caption');

        $backup = new \SQLite3($backupDatabasePath);
        $backup->exec('CREATE TABLE state (value TEXT)');
        $backup->exec("INSERT INTO state VALUES ('new')");
        $backup->exec('CREATE TABLE media_subtitles (id INTEGER PRIMARY KEY)');
        $backup->exec('INSERT INTO media_subtitles DEFAULT VALUES');
        $backup->exec('CREATE TABLE media_items (id TEXT PRIMARY KEY, metadata TEXT)');
        $backup->exec(
            "INSERT INTO media_items VALUES ('item-1', "
            ."'{".'"poster_cached":true,"backdrop_cached":true,"title":"Example"'."}')",
        );
        $backup->close();
        $this->createBackup($archivePath, $backupDatabasePath, $newKey);

        $previousDatabase = config('database.connections.sqlite.database');
        $previousDataPath = config('odissey.data_path');
        $previousArtworkPath = config('odissey.artwork_path');
        $previousCaptionPath = config('odissey.caption_path');
        $previousSource = getenv('ODISSEY_APP_KEY_SOURCE');
        putenv('ODISSEY_APP_KEY_SOURCE=file');
        config([
            'app.key' => $oldKey,
            'database.connections.sqlite.database' => $databasePath,
            'odissey.data_path' => $directory,
            'odissey.artwork_path' => $artworkPath,
            'odissey.caption_path' => $captionPath,
        ]);

        try {
            $this->artisan('odissey:restore', [
                'path' => $archivePath,
                '--force' => true,
                '--offline' => true,
            ])->assertSuccessful();

            $restored = new \SQLite3($databasePath, SQLITE3_OPEN_READONLY);
            $this->assertSame('new', $restored->querySingle('SELECT value FROM state'));
            $this->assertSame(0, $restored->querySingle('SELECT COUNT(*) FROM media_subtitles'));
            $metadata = json_decode(
                $restored->querySingle("SELECT metadata FROM media_items WHERE id = 'item-1'"),
                true,
            );
            $restored->close();
            $this->assertArrayNotHasKey('poster_cached', $metadata);
            $this->assertArrayNotHasKey('backdrop_cached', $metadata);
            $this->assertSame($newKey, trim(File::get($keyPath)));

            $previous = new \SQLite3($databasePath.'.before-restore', SQLITE3_OPEN_READONLY);
            $this->assertSame('old', $previous->querySingle('SELECT value FROM state'));
            $previous->close();
            $this->assertSame($oldKey, trim(File::get($keyPath.'.before-restore')));
            $this->assertFileExists($artworkPath.'/old.jpg');
            $this->assertFileExists($captionPath.'/old.vtt');
            $this->assertFileDoesNotExist($databasePath.'.restore-in-progress');
        } finally {
            config([
                'database.connections.sqlite.database' => $previousDatabase,
                'odissey.data_path' => $previousDataPath,
                'odissey.artwork_path' => $previousArtworkPath,
                'odissey.caption_path' => $previousCaptionPath,
            ]);
            $previousSource === false
                ? putenv('ODISSEY_APP_KEY_SOURCE')
                : putenv('ODISSEY_APP_KEY_SOURCE='.$previousSource);
            File::deleteDirectory($directory);
        }
    }

    private function createBackup(
        string $archivePath,
        string $databasePath,
        string $key,
        ?array $migrations = null,
        string $application = ApplicationBackupCompatibility::APPLICATION,
    ): void {
        $migrations ??= $this->availableMigrations();
        $sqlite = new \SQLite3($databasePath);
        $sqlite->exec(
            'CREATE TABLE IF NOT EXISTS migrations ('
            .'id INTEGER PRIMARY KEY AUTOINCREMENT, migration TEXT NOT NULL, batch INTEGER NOT NULL'
            .')',
        );
        $sqlite->exec('DELETE FROM migrations');
        $statement = $sqlite->prepare(
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
        $sqlite->close();

        $database = File::get($databasePath);
        $zip = new ZipArchive;
        $this->assertTrue(
            $zip->open($archivePath, ZipArchive::CREATE | ZipArchive::OVERWRITE),
        );
        $zip->addFromString('database.sqlite', $database);
        $zip->addFromString('app.key', $key."\n");
        $zip->addFromString('manifest.json', json_encode([
            'format' => ApplicationBackupCompatibility::FORMAT,
            'application' => $application,
            'application_version' => 'test',
            'database_sha256' => hash('sha256', $database),
            'migrations' => $migrations,
            'schema_sha256' => hash('sha256', implode("\n", $migrations)),
        ], JSON_THROW_ON_ERROR));
        $zip->close();
    }

    /**
     * @return list<string>
     */
    private function availableMigrations(): array
    {
        $paths = glob(database_path('migrations/*.php'));
        $this->assertIsArray($paths);
        $migrations = array_map(
            static fn (string $path): string => pathinfo($path, PATHINFO_FILENAME),
            $paths,
        );
        sort($migrations, SORT_STRING);

        return array_values($migrations);
    }
}
