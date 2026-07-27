<?php

namespace App\Console\Commands;

use App\Services\Backup\ApplicationBackupCompatibility;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Symfony\Component\Process\Process;
use Throwable;
use ZipArchive;

class RestoreApplication extends Command
{
    protected $signature = 'odissey:restore
                            {path : Backup .zip path}
                            {--force : Confirm replacement of current database and key}
                            {--offline : Confirm every other Odissey process is stopped}';

    protected $description = 'Validate and restore an Odissey database and application key backup';

    public function handle(ApplicationBackupCompatibility $compatibility): int
    {
        if (! $this->option('force')) {
            $this->error('Restore requires --force.');

            return self::FAILURE;
        }

        if (! $this->option('offline')) {
            $this->error(
                'Restore requires --offline after all web, queue, scheduler, and media processes are stopped.',
            );

            return self::FAILURE;
        }

        $this->assertSupervisedProcessesAreStopped();
        $maximumDatabaseBytes = min(
            100 * 1024 * 1024 * 1024,
            max(1024 * 1024, (int) config(
                'odissey.backup_max_database_bytes',
                10 * 1024 * 1024 * 1024,
            )),
        );
        $archivePath = (string) $this->argument('path');
        $this->assertSafeArchiveFile($archivePath, $maximumDatabaseBytes);

        $zip = new ZipArchive;
        if ($zip->open($archivePath) !== true) {
            throw new RuntimeException('Backup cannot be opened.');
        }
        $stagedDatabase = null;
        $stagedKey = null;

        try {
            if ($zip->numFiles !== 3) {
                throw new RuntimeException('Backup contains unexpected entries.');
            }

            $this->assertSafeEntry($zip, 'manifest.json', 64 * 1024);
            $this->assertSafeEntry($zip, 'app.key', 1024);
            $this->assertSafeEntry($zip, 'database.sqlite', $maximumDatabaseBytes);

            $manifest = json_decode($zip->getFromName('manifest.json') ?: '', true);
            $key = trim($zip->getFromName('app.key') ?: '');
            if (
                ! is_array($manifest)
                || ! is_string($manifest['database_sha256'] ?? null)
                || ! preg_match('/\A[a-f0-9]{64}\z/', $manifest['database_sha256'])
                || ! $this->isValidApplicationKey($key)
            ) {
                throw new RuntimeException('Backup validation failed.');
            }
            $expectedMigrations = $compatibility->validateManifest($manifest);

            $this->assertExternallyManagedKeyMatches($key);

            $databasePath = $this->managedFilePath(
                (string) config('database.connections.sqlite.database'),
                mustExist: true,
            );
            $this->assertNoRestoreMarker($databasePath);
            $keyPath = (string) env(
                'ODISSEY_APP_KEY_FILE',
                rtrim(config('odissey.data_path'), '/').'/app.key',
            );
            File::ensureDirectoryExists(dirname($keyPath), 0700);
            $keyPath = $this->managedFilePath($keyPath);

            $stagedDatabase = tempnam(
                dirname($databasePath),
                '.odissey-restore-db-',
            );
            if ($stagedDatabase === false) {
                throw new RuntimeException('Restore database staging file cannot be created.');
            }
            if (! chmod($stagedDatabase, 0600)) {
                throw new RuntimeException('Restore database staging permissions cannot be secured.');
            }

            $databaseHash = $this->copyDatabaseEntry(
                $zip,
                $stagedDatabase,
                $maximumDatabaseBytes,
            );
            if (! hash_equals($manifest['database_sha256'], $databaseHash)) {
                throw new RuntimeException('Backup validation failed.');
            }

            $this->validateAndReconcileDatabase(
                $stagedDatabase,
                $expectedMigrations,
                $compatibility,
            );
            $this->syncFile($stagedDatabase);

            $stagedKey = tempnam(
                dirname($keyPath),
                '.odissey-restore-key-',
            );
            if ($stagedKey === false) {
                throw new RuntimeException('Restore key staging file cannot be created.');
            }
            $this->writeKey($stagedKey, $key);

            $this->checkpointAndCloseLiveDatabase($databasePath);
            $this->installRollbackPair(
                $stagedDatabase,
                $stagedKey,
                $databasePath,
                $keyPath,
            );
            $this->warn(
                'Restore complete. Restart the container before serving requests. '
                .'Previous database/key pair: '.$databasePath.'.before-restore and '
                .$keyPath.'.before-restore',
            );

            return self::SUCCESS;
        } finally {
            $zip->close();
            if (is_string($stagedDatabase)) {
                File::delete($stagedDatabase);
            }
            if (is_string($stagedKey)) {
                File::delete($stagedKey);
            }
        }
    }

    private function assertSafeArchiveFile(
        string $path,
        int $maximumDatabaseBytes,
    ): void {
        $maximumArchiveBytes = $maximumDatabaseBytes + (1024 * 1024);

        if (
            $path === ''
            || str_contains($path, "\0")
            || is_link($path)
            || ! is_file($path)
        ) {
            throw new RuntimeException('Backup path is unavailable or unsafe.');
        }

        $archiveBytes = filesize($path);
        if (
            ! is_int($archiveBytes)
            || $archiveBytes < 1
            || $archiveBytes > $maximumArchiveBytes
        ) {
            throw new RuntimeException('Backup archive exceeds the restore limit.');
        }
    }

    private function assertSafeEntry(ZipArchive $zip, string $name, int $maximumBytes): void
    {
        $matches = 0;
        for ($index = 0; $index < $zip->numFiles; $index++) {
            if ($zip->getNameIndex($index) === $name) {
                $matches++;
            }
        }

        $stat = $zip->statName($name);
        $size = is_array($stat) ? ($stat['size'] ?? null) : null;
        $compressedSize = is_array($stat) ? ($stat['comp_size'] ?? null) : null;

        if (
            ! is_int($size)
            || ! is_int($compressedSize)
            || $matches !== 1
            || $size < 1
            || $size > $maximumBytes
            || ($compressedSize === 0 && $size > 1024)
            || ($compressedSize > 0 && $size / $compressedSize > 1000)
        ) {
            throw new RuntimeException('Backup entry is missing or exceeds safety limits.');
        }
    }

    private function copyDatabaseEntry(ZipArchive $zip, string $path, int $maximumBytes): string
    {
        $input = $zip->getStream('database.sqlite');
        $output = @fopen($path, 'wb');
        if (! is_resource($input) || $output === false) {
            if (is_resource($input)) {
                fclose($input);
            }

            throw new RuntimeException('Backup database cannot be staged.');
        }

        $hash = hash_init('sha256');
        $bytes = 0;
        $emptyReads = 0;

        try {
            while (! feof($input)) {
                $chunk = fread($input, 1024 * 1024);
                if ($chunk === false) {
                    throw new RuntimeException('Backup database cannot be read.');
                }
                if ($chunk === '') {
                    if (++$emptyReads >= 3) {
                        throw new RuntimeException('Backup database cannot be read.');
                    }

                    continue;
                }

                $emptyReads = 0;
                $bytes += strlen($chunk);
                if ($bytes > $maximumBytes) {
                    throw new RuntimeException('Backup database exceeds the restore limit.');
                }

                hash_update($hash, $chunk);
                $offset = 0;
                while ($offset < strlen($chunk)) {
                    $written = fwrite($output, substr($chunk, $offset));
                    if ($written === false || $written === 0) {
                        throw new RuntimeException('Backup database cannot be staged.');
                    }
                    $offset += $written;
                }
            }

            if (
                $bytes < 1
                || ! fflush($output)
                || (function_exists('fsync') && ! fsync($output))
            ) {
                throw new RuntimeException('Backup database cannot be staged.');
            }
        } finally {
            fclose($input);
            fclose($output);
        }

        return hash_final($hash);
    }

    private function assertSupervisedProcessesAreStopped(): void
    {
        $configuration = '/etc/supervisor/conf.d/odissey.conf';
        $socket = '/tmp/odissey-supervisor.sock';
        if (! File::isFile($configuration) || ! file_exists($socket)) {
            return;
        }

        $process = new Process([
            '/usr/bin/supervisorctl',
            '-c',
            $configuration,
            'status',
            'web',
            'queue',
            'queue-media-discovery',
            'queue-media-scan:*',
            'queue-media-enrichment:*',
            'scheduler',
            'media-supervisor',
        ], timeout: 10);
        $process->run();
        $output = $process->getOutput()."\n".$process->getErrorOutput();

        foreach ([
            'web',
            'queue',
            'queue-media-discovery',
            'queue-media-scan:queue-media-scan_00',
            'queue-media-scan:queue-media-scan_01',
            'queue-media-enrichment:queue-media-enrichment_00',
            'queue-media-enrichment:queue-media-enrichment_01',
            'scheduler',
            'media-supervisor',
        ] as $program) {
            if (preg_match(
                '/^'.preg_quote($program, '/').'\s+(?:RUNNING|STARTING|BACKOFF|STOPPING)\b/m',
                $output,
            ) === 1) {
                throw new RuntimeException(
                    'Restore refused because supervised Odissey processes are still active.',
                );
            }

            if (preg_match(
                '/^'.preg_quote($program, '/').'\s+(?:STOPPED|EXITED|FATAL)\b/m',
                $output,
            ) !== 1) {
                throw new RuntimeException(
                    'Restore cannot verify that supervised Odissey processes are stopped.',
                );
            }
        }
    }

    private function assertExternallyManagedKeyMatches(string $backupKey): void
    {
        $activeKey = trim((string) config('app.key'));
        $source = (string) env('ODISSEY_APP_KEY_SOURCE', '');
        $environmentKey = getenv('APP_KEY');
        $externallyManaged = $source === 'environment'
            || (
                $source === ''
                && is_string($environmentKey)
                && trim($environmentKey) !== ''
            );

        if (
            $externallyManaged
            && (
                ! $this->isValidApplicationKey($activeKey)
                || ! hash_equals($activeKey, $backupKey)
            )
        ) {
            throw new RuntimeException(
                'Backup key does not match externally managed APP_KEY; update the secret while offline before restoring.',
            );
        }
    }

    private function managedFilePath(
        string $path,
        bool $mustExist = false,
    ): string {
        if (
            $path === ''
            || ! str_starts_with($path, DIRECTORY_SEPARATOR)
            || str_contains($path, "\0")
            || in_array(basename($path), ['', '.', '..'], true)
        ) {
            throw new RuntimeException('Restore target path is unsafe.');
        }

        $parent = realpath(dirname($path));
        if ($parent === false || ! is_dir($parent)) {
            throw new RuntimeException('Restore target directory is unavailable.');
        }
        $normalized = rtrim($parent, DIRECTORY_SEPARATOR)
            .DIRECTORY_SEPARATOR.basename($path);

        if (
            is_link($normalized)
            || ($mustExist && ! is_file($normalized))
        ) {
            throw new RuntimeException('Restore target file is unavailable or unsafe.');
        }

        return $normalized;
    }

    /**
     * @param  list<string>  $expectedMigrations
     */
    private function validateAndReconcileDatabase(
        string $path,
        array $expectedMigrations,
        ApplicationBackupCompatibility $compatibility,
    ): void {
        $database = new \SQLite3($path, SQLITE3_OPEN_READWRITE);

        try {
            if ($database->querySingle('PRAGMA integrity_check') !== 'ok') {
                throw new RuntimeException('Backup database integrity check failed.');
            }

            if (
                $compatibility->databaseMigrations($database)
                !== $expectedMigrations
            ) {
                throw new RuntimeException(
                    'Backup database does not match its compatibility metadata.',
                );
            }

            if (! $database->exec('BEGIN IMMEDIATE')) {
                throw new RuntimeException('Backup cache state cannot be reconciled.');
            }

            try {
                if ($this->sqliteTableExists($database, 'media_subtitles')) {
                    if (! $database->exec('DELETE FROM media_subtitles')) {
                        throw new RuntimeException('Backup caption cache cannot be invalidated.');
                    }
                }

                if (
                    $this->sqliteTableExists($database, 'media_items')
                    && $this->sqliteColumnExists($database, 'media_items', 'metadata')
                ) {
                    $this->clearArtworkMarkers($database);
                }

                if (! $database->exec('COMMIT')) {
                    throw new RuntimeException('Backup cache reconciliation cannot be committed.');
                }
            } catch (Throwable $exception) {
                $database->exec('ROLLBACK');

                throw $exception;
            }

            if ($database->querySingle('PRAGMA integrity_check') !== 'ok') {
                throw new RuntimeException('Reconciled backup database integrity check failed.');
            }
        } finally {
            $database->close();
        }
    }

    private function sqliteTableExists(\SQLite3 $database, string $table): bool
    {
        $statement = $database->prepare(
            "SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = :name",
        );
        if ($statement === false) {
            throw new RuntimeException('Backup schema cannot be inspected.');
        }
        $statement->bindValue(':name', $table, SQLITE3_TEXT);
        $result = $statement->execute();
        if ($result === false) {
            throw new RuntimeException('Backup schema cannot be inspected.');
        }

        try {
            $row = $result->fetchArray(SQLITE3_NUM);

            return is_array($row) && (int) ($row[0] ?? 0) === 1;
        } finally {
            $result->finalize();
        }
    }

    private function clearArtworkMarkers(\SQLite3 $database): void
    {
        $result = $database->query(
            'SELECT id, metadata FROM media_items WHERE metadata IS NOT NULL',
        );
        $update = $database->prepare(
            'UPDATE media_items SET metadata = :metadata WHERE id = :id',
        );
        if ($result === false || $update === false) {
            throw new RuntimeException('Backup artwork cache cannot be inspected.');
        }

        try {
            while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
                $metadataBytes = (string) ($row['metadata'] ?? '');
                if (strlen($metadataBytes) > 4 * 1024 * 1024) {
                    throw new RuntimeException('Backup media metadata exceeds the safety limit.');
                }

                try {
                    $metadata = json_decode(
                        $metadataBytes,
                        true,
                        128,
                        JSON_THROW_ON_ERROR,
                    );
                } catch (Throwable) {
                    throw new RuntimeException('Backup media metadata is invalid.');
                }

                if (! is_array($metadata)) {
                    continue;
                }

                $changed = array_key_exists('poster_cached', $metadata)
                    || array_key_exists('backdrop_cached', $metadata);
                unset($metadata['poster_cached'], $metadata['backdrop_cached']);
                if (! $changed) {
                    continue;
                }

                $encoded = json_encode(
                    $metadata,
                    JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
                );
                $update->bindValue(':metadata', $encoded, SQLITE3_TEXT);
                $update->bindValue(':id', (string) $row['id'], SQLITE3_TEXT);
                $updated = $update->execute();
                if ($updated === false) {
                    throw new RuntimeException('Backup artwork cache cannot be invalidated.');
                }
                $updated->finalize();
                $update->reset();
            }
        } finally {
            $result->finalize();
        }
    }

    private function sqliteColumnExists(
        \SQLite3 $database,
        string $table,
        string $column,
    ): bool {
        $result = $database->query(
            'PRAGMA table_info("'.str_replace('"', '""', $table).'")',
        );
        if ($result === false) {
            throw new RuntimeException('Backup schema cannot be inspected.');
        }

        try {
            while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
                if (($row['name'] ?? null) === $column) {
                    return true;
                }
            }

            return false;
        } finally {
            $result->finalize();
        }
    }

    private function writeKey(string $path, string $key): void
    {
        $handle = @fopen($path, 'wb');
        if ($handle === false) {
            throw new RuntimeException('Restore key cannot be staged.');
        }
        $bytes = $key."\n";

        try {
            $written = fwrite($handle, $bytes);
            if (
                $written !== strlen($bytes)
                || ! fflush($handle)
                || (function_exists('fsync') && ! fsync($handle))
            ) {
                throw new RuntimeException('Restore key cannot be staged.');
            }
        } finally {
            fclose($handle);
        }

        if (! chmod($path, 0600)) {
            throw new RuntimeException('Restore key staging permissions cannot be secured.');
        }
    }

    private function syncFile(string $path): void
    {
        if (! function_exists('fsync')) {
            return;
        }

        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            throw new RuntimeException('Restore staging file cannot be synchronized.');
        }

        try {
            if (! fsync($handle)) {
                throw new RuntimeException('Restore staging file cannot be synchronized.');
            }
        } finally {
            fclose($handle);
        }
    }

    private function checkpointAndCloseLiveDatabase(string $databasePath): void
    {
        DB::disconnect('sqlite');
        DB::purge('sqlite');

        $database = new \SQLite3($databasePath, SQLITE3_OPEN_READWRITE);
        $database->busyTimeout(5000);

        try {
            $checkpoint = $database->querySingle(
                'PRAGMA wal_checkpoint(TRUNCATE)',
                true,
            );
            if (
                ! is_array($checkpoint)
                || (int) ($checkpoint['busy'] ?? 1) !== 0
            ) {
                throw new RuntimeException('Live database cannot be checkpointed for restore.');
            }
        } finally {
            $database->close();
        }

        foreach (['-wal', '-shm', '-journal'] as $suffix) {
            $path = $databasePath.$suffix;
            if (file_exists($path) && ! @unlink($path)) {
                throw new RuntimeException('Live database journal cannot be cleared safely.');
            }
        }
    }

    private function installRollbackPair(
        string $stagedDatabase,
        string $stagedKey,
        string $databasePath,
        string $keyPath,
    ): void {
        $databaseBackup = $databasePath.'.before-restore';
        $keyBackup = $keyPath.'.before-restore';
        $restoreMarker = $this->restoreMarkerPath($databasePath);
        $hadKey = is_file($keyPath);

        if (count(array_unique([
            $databasePath,
            $keyPath,
            $databaseBackup,
            $keyBackup,
            $restoreMarker,
        ])) !== 5) {
            throw new RuntimeException('Restore database and key paths overlap unsafely.');
        }

        if (is_link($restoreMarker) || file_exists($restoreMarker)) {
            throw new RuntimeException(
                'An interrupted restore marker exists; recover the database/key pair before retrying.',
            );
        }

        foreach ([$databaseBackup, $keyBackup] as $backupPath) {
            if (
                is_link($backupPath)
                || is_dir($backupPath)
                || (file_exists($backupPath) && ! @unlink($backupPath))
            ) {
                throw new RuntimeException('Previous restore rollback path is unsafe.');
            }
        }

        if (! chmod($stagedDatabase, 0660)) {
            throw new RuntimeException('Staged database permissions cannot be secured.');
        }

        $this->createRestoreMarker(
            $restoreMarker,
            $databasePath,
            $keyPath,
            $databaseBackup,
            $keyBackup,
        );

        $databaseMoved = false;
        $keyMoved = false;
        $databaseInstalled = false;
        $keyInstalled = false;
        try {
            if (! rename($databasePath, $databaseBackup)) {
                throw new RuntimeException('Current database cannot be preserved for rollback.');
            }
            $databaseMoved = true;

            if ($hadKey) {
                if (! rename($keyPath, $keyBackup)) {
                    throw new RuntimeException('Current application key cannot be preserved for rollback.');
                }
                $keyMoved = true;
            }

            if (! rename($stagedDatabase, $databasePath)) {
                throw new RuntimeException('Staged database cannot be installed.');
            }
            $databaseInstalled = true;
            if (! rename($stagedKey, $keyPath)) {
                throw new RuntimeException('Staged application key cannot be installed.');
            }
            $keyInstalled = true;
            if (
                ! chmod($databasePath, 0660)
                || ! chmod($databaseBackup, 0600)
                || ! chmod($keyPath, 0600)
                || ($keyMoved && ! chmod($keyBackup, 0600))
            ) {
                throw new RuntimeException('Restored file permissions cannot be secured.');
            }

            $this->syncFile($databasePath);
            $this->syncFile($keyPath);
            $this->syncDirectory(dirname($databasePath));
            if (dirname($keyPath) !== dirname($databasePath)) {
                $this->syncDirectory(dirname($keyPath));
            }
        } catch (Throwable $exception) {
            $rollbackFailed = false;

            if (
                $databaseInstalled
                && file_exists($databasePath)
                && ! @unlink($databasePath)
            ) {
                $rollbackFailed = true;
            }
            if (
                $databaseMoved
                && ! rename($databaseBackup, $databasePath)
            ) {
                $rollbackFailed = true;
            }

            if (
                $keyInstalled
                && file_exists($keyPath)
                && ! @unlink($keyPath)
            ) {
                $rollbackFailed = true;
            }
            if ($keyMoved && ! rename($keyBackup, $keyPath)) {
                $rollbackFailed = true;
            }

            if (! $rollbackFailed) {
                try {
                    $this->syncDirectory(dirname($databasePath));
                    if (dirname($keyPath) !== dirname($databasePath)) {
                        $this->syncDirectory(dirname($keyPath));
                    }
                    $this->clearRestoreMarker($restoreMarker);
                } catch (Throwable) {
                    $rollbackFailed = true;
                }
            }

            if ($rollbackFailed) {
                throw new RuntimeException(
                    'Restore failed and rollback could not be completed; keep the service offline.',
                    0,
                    $exception,
                );
            }

            throw $exception;
        }

        $this->clearRestoreMarker($restoreMarker);
    }

    private function createRestoreMarker(
        string $markerPath,
        string $databasePath,
        string $keyPath,
        string $databaseBackup,
        string $keyBackup,
    ): void {
        $payload = json_encode([
            'format' => 1,
            'started_at' => now()->toIso8601String(),
            'database' => $databasePath,
            'key' => $keyPath,
            'database_backup' => $databaseBackup,
            'key_backup' => $keyBackup,
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";

        $handle = @fopen($markerPath, 'x+b');
        if ($handle === false) {
            throw new RuntimeException(
                'Restore recovery marker cannot be created; keep the service offline.',
            );
        }

        try {
            if (! chmod($markerPath, 0600)) {
                throw new RuntimeException('Restore recovery marker permissions cannot be secured.');
            }

            $offset = 0;
            while ($offset < strlen($payload)) {
                $written = fwrite($handle, substr($payload, $offset));
                if ($written === false || $written === 0) {
                    throw new RuntimeException('Restore recovery marker cannot be written.');
                }
                $offset += $written;
            }

            if (
                ! fflush($handle)
                || (function_exists('fsync') && ! fsync($handle))
            ) {
                throw new RuntimeException('Restore recovery marker cannot be synchronized.');
            }
        } catch (Throwable $exception) {
            fclose($handle);
            @unlink($markerPath);

            throw $exception;
        }

        fclose($handle);

        try {
            $this->syncDirectory(dirname($markerPath));
        } catch (Throwable $exception) {
            @unlink($markerPath);

            throw $exception;
        }
    }

    private function clearRestoreMarker(string $markerPath): void
    {
        if (
            is_link($markerPath)
            || ! is_file($markerPath)
            || ! @unlink($markerPath)
        ) {
            throw new RuntimeException(
                'Restore recovery marker cannot be cleared; keep the service offline.',
            );
        }

        $this->syncDirectory(dirname($markerPath));
    }

    private function assertNoRestoreMarker(string $databasePath): void
    {
        $markerPath = $this->restoreMarkerPath($databasePath);

        if (is_link($markerPath) || file_exists($markerPath)) {
            throw new RuntimeException(
                'An interrupted restore marker exists; recover the database/key pair before retrying.',
            );
        }
    }

    private function restoreMarkerPath(string $databasePath): string
    {
        return $databasePath.'.restore-in-progress';
    }

    private function syncDirectory(string $directory): void
    {
        if (! function_exists('fsync')) {
            return;
        }

        $handle = @fopen($directory, 'rb');
        if ($handle === false) {
            throw new RuntimeException('Restore directory cannot be synchronized.');
        }

        try {
            if (! fsync($handle)) {
                throw new RuntimeException('Restore directory cannot be synchronized.');
            }
        } finally {
            fclose($handle);
        }
    }

    private function isValidApplicationKey(string $key): bool
    {
        if (! str_starts_with($key, 'base64:') || strlen($key) > 256) {
            return false;
        }

        $decoded = base64_decode(substr($key, 7), true);

        return is_string($decoded) && strlen($decoded) === 32;
    }
}
