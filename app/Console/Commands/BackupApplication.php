<?php

namespace App\Console\Commands;

use App\Services\Backup\ApplicationBackupCompatibility;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use RuntimeException;
use ZipArchive;

class BackupApplication extends Command
{
    protected $signature = 'odissey:backup {path : Absolute destination .zip path}';

    protected $description = 'Create a portable encrypted-settings-capable database and key backup';

    public function handle(ApplicationBackupCompatibility $compatibility): int
    {
        $destination = $this->safeDestination((string) $this->argument('path'));
        $this->assertNoPreviousKeys();

        $key = trim((string) config('app.key'));
        if (! $this->isValidApplicationKey($key)) {
            throw new RuntimeException('The active application key is unavailable or invalid.');
        }

        $keyPath = env(
            'ODISSEY_APP_KEY_FILE',
            rtrim(config('odissey.data_path'), '/').'/app.key',
        );
        if (File::isFile($keyPath)) {
            $fileKey = trim(File::get($keyPath));
            if (! hash_equals($key, $fileKey)) {
                throw new RuntimeException(
                    'The file-backed application key does not match the active APP_KEY.',
                );
            }
        }

        $previousUmask = umask(0077);
        $temporary = null;
        $archiveStaged = null;
        $zip = null;

        try {
            $temporary = tempnam(sys_get_temp_dir(), 'odissey-db-');
            if ($temporary === false) {
                throw new RuntimeException('Backup staging file cannot be created.');
            }
            File::delete($temporary);
            $archiveStaged = tempnam(
                dirname($destination),
                '.odissey-backup-',
            );
            if (
                $archiveStaged === false
                || ! chmod($archiveStaged, 0600)
            ) {
                throw new RuntimeException('Backup archive staging file cannot be created securely.');
            }

            DB::statement('PRAGMA wal_checkpoint(FULL)');
            DB::statement("VACUUM INTO '".str_replace("'", "''", $temporary)."'");

            $maximumDatabaseBytes = min(
                100 * 1024 * 1024 * 1024,
                max(1024 * 1024, (int) config(
                    'odissey.backup_max_database_bytes',
                    10 * 1024 * 1024 * 1024,
                )),
            );
            if (
                ! File::isFile($temporary)
                || File::size($temporary) < 1
                || File::size($temporary) > $maximumDatabaseBytes
            ) {
                throw new RuntimeException('Database exceeds the backup size limit.');
            }
            if (
                ! chmod($temporary, 0600)
                || ! $this->hasPrivatePermissions($temporary)
                || ! $this->hasPrivatePermissions($archiveStaged)
            ) {
                throw new RuntimeException('Backup staging permissions could not be secured.');
            }

            $databaseHash = hash_file('sha256', $temporary);
            if (! is_string($databaseHash)) {
                throw new RuntimeException('Database backup hash cannot be calculated.');
            }

            $database = new \SQLite3($temporary, SQLITE3_OPEN_READONLY);
            try {
                $migrations = $compatibility->databaseMigrations($database);
            } finally {
                $database->close();
            }

            $zip = new ZipArchive;
            if ($zip->open($archiveStaged, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                throw new RuntimeException('Backup destination cannot be opened.');
            }

            $manifest = json_encode([
                'format' => ApplicationBackupCompatibility::FORMAT,
                'application' => ApplicationBackupCompatibility::APPLICATION,
                'application_version' => $compatibility->release(),
                'created_at' => now()->toIso8601String(),
                'database_sha256' => $databaseHash,
                'migrations' => $migrations,
                'schema_sha256' => $compatibility->schemaHash($migrations),
            ], JSON_THROW_ON_ERROR);
            if (
                ! $zip->addFile($temporary, 'database.sqlite')
                || ! $zip->addFromString('app.key', $key."\n")
                || ! $zip->addFromString('manifest.json', $manifest)
                || ! $zip->close()
            ) {
                throw new RuntimeException('Backup archive could not be finalized.');
            }
            $zip = null;

            if (
                ! chmod($archiveStaged, 0600)
                || ! rename($archiveStaged, $destination)
            ) {
                throw new RuntimeException('Backup permissions could not be secured.');
            }
            $archiveStaged = null;

            $this->info('Backup created at '.$destination);

            return self::SUCCESS;
        } finally {
            if ($zip instanceof ZipArchive) {
                $zip->close();
            }
            if (is_string($temporary)) {
                File::delete($temporary);
            }
            if (is_string($archiveStaged)) {
                File::delete($archiveStaged);
            }
            umask($previousUmask);
        }
    }

    private function hasPrivatePermissions(string $path): bool
    {
        $permissions = fileperms($path);

        return is_int($permissions) && ($permissions & 0777) === 0600;
    }

    private function isValidApplicationKey(string $key): bool
    {
        if (! str_starts_with($key, 'base64:') || strlen($key) > 256) {
            return false;
        }

        $decoded = base64_decode(substr($key, 7), true);

        return is_string($decoded) && strlen($decoded) === 32;
    }

    private function assertNoPreviousKeys(): void
    {
        $previousKeys = config('app.previous_keys', []);
        if (
            is_array($previousKeys)
            && array_filter(
                $previousKeys,
                static fn (mixed $key): bool => is_string($key) && trim($key) !== '',
            ) !== []
        ) {
            throw new RuntimeException(
                'Backup refused while APP_PREVIOUS_KEYS is configured; '
                .'re-encrypt persisted values with the active key first.',
            );
        }
    }

    private function safeDestination(string $destination): string
    {
        if (! str_ends_with(strtolower($destination), '.zip')) {
            $destination .= '.zip';
        }

        if (
            $destination === ''
            || str_contains($destination, "\0")
            || ! str_starts_with($destination, DIRECTORY_SEPARATOR)
            || in_array(basename($destination), ['', '.', '..'], true)
            || strlen(basename($destination)) > 255
        ) {
            throw new RuntimeException(
                'Backup destination must be an absolute path in an existing safe directory.',
            );
        }

        $parent = realpath(dirname($destination));
        if (
            $parent === false
            || ! is_dir($parent)
            || ! is_writable($parent)
        ) {
            throw new RuntimeException('Backup destination directory is unavailable.');
        }

        $resolved = rtrim($parent, DIRECTORY_SEPARATOR)
            .DIRECTORY_SEPARATOR.basename($destination);
        if (is_link($resolved) || is_dir($resolved)) {
            throw new RuntimeException('Backup destination is a symlink or directory.');
        }

        $public = realpath(public_path());
        $publicStorage = realpath(storage_path('app/public'));
        $applicationRoot = realpath(base_path());
        $lexicalPublic = rtrim(public_path(), DIRECTORY_SEPARATOR);
        $lexicalPublicStorage = rtrim(
            storage_path('app/public'),
            DIRECTORY_SEPARATOR,
        );
        $lexicalApplicationRoot = rtrim(base_path(), DIRECTORY_SEPARATOR);
        foreach (array_filter([
            $public,
            $publicStorage,
            $applicationRoot,
            $lexicalPublic,
            $lexicalPublicStorage,
            $lexicalApplicationRoot,
        ], 'is_string') as $unsafeRoot) {
            if ($this->pathIsWithin($destination, $unsafeRoot)
                || $this->pathIsWithin($resolved, $unsafeRoot)) {
                throw new RuntimeException(
                    'Backup destination cannot be inside the application or a web-served directory.',
                );
            }
        }

        return $resolved;
    }

    private function pathIsWithin(string $path, string $root): bool
    {
        $path = rtrim($path, DIRECTORY_SEPARATOR);
        $root = rtrim($root, DIRECTORY_SEPARATOR);

        return $path === $root
            || str_starts_with($path, $root.DIRECTORY_SEPARATOR);
    }
}
