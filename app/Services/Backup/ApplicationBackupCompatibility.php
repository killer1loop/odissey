<?php

namespace App\Services\Backup;

use RuntimeException;

class ApplicationBackupCompatibility
{
    public const APPLICATION = 'odissey';

    public const FORMAT = 2;

    /**
     * @return list<string>
     */
    public function availableMigrations(): array
    {
        $paths = glob(database_path('migrations/*.php'));
        if (! is_array($paths) || $paths === []) {
            throw new RuntimeException('Application migration metadata is unavailable.');
        }

        $migrations = array_map(
            static fn (string $path): string => pathinfo($path, PATHINFO_FILENAME),
            $paths,
        );
        sort($migrations, SORT_STRING);

        return array_values($migrations);
    }

    /**
     * @return list<string>
     */
    public function databaseMigrations(\SQLite3 $database): array
    {
        $exists = $database->querySingle(
            "SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = 'migrations'",
        );
        if ((int) $exists !== 1) {
            throw new RuntimeException('Backup database migration state is unavailable.');
        }

        $result = $database->query('SELECT migration FROM migrations ORDER BY id ASC');
        if ($result === false) {
            throw new RuntimeException('Backup database migration state cannot be read.');
        }

        $migrations = [];

        try {
            while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
                $migration = $row['migration'] ?? null;
                if (
                    ! is_string($migration)
                    || preg_match('/\A[a-zA-Z0-9_]{1,255}\z/', $migration) !== 1
                    || in_array($migration, $migrations, true)
                ) {
                    throw new RuntimeException('Backup database migration state is invalid.');
                }

                $migrations[] = $migration;
            }
        } finally {
            $result->finalize();
        }

        if ($migrations === []) {
            throw new RuntimeException('Backup database migration state is unavailable.');
        }

        $this->assertSupportedMigrations($migrations);

        return $migrations;
    }

    /**
     * @param  list<string>  $migrations
     */
    public function assertSupportedMigrations(array $migrations): void
    {
        $available = $this->availableMigrations();
        $expectedPrefix = array_slice($available, 0, count($migrations));

        if ($migrations === [] || $migrations !== $expectedPrefix) {
            throw new RuntimeException(
                'Backup schema is not compatible with this Odissey release.',
            );
        }
    }

    /**
     * @param  list<string>  $migrations
     */
    public function schemaHash(array $migrations): string
    {
        return hash('sha256', implode("\n", $migrations));
    }

    public function release(): string
    {
        $release = trim((string) config('odissey.release', 'development'));

        if (
            $release === ''
            || strlen($release) > 128
            || preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9._+-]*\z/', $release) !== 1
        ) {
            return 'development';
        }

        return $release;
    }

    /**
     * @param  array<string, mixed>  $manifest
     * @return list<string>
     */
    public function validateManifest(array $manifest): array
    {
        $migrations = $manifest['migrations'] ?? null;
        if (
            ($manifest['format'] ?? null) !== self::FORMAT
            || ($manifest['application'] ?? null) !== self::APPLICATION
            || ! is_string($manifest['application_version'] ?? null)
            || strlen($manifest['application_version']) < 1
            || strlen($manifest['application_version']) > 128
            || preg_match(
                '/\A[a-zA-Z0-9][a-zA-Z0-9._+-]*\z/',
                $manifest['application_version'],
            ) !== 1
            || ! is_array($migrations)
            || ! array_is_list($migrations)
            || count($migrations) > 1024
            || ! is_string($manifest['schema_sha256'] ?? null)
            || preg_match('/\A[a-f0-9]{64}\z/', $manifest['schema_sha256']) !== 1
        ) {
            throw new RuntimeException('Backup compatibility metadata is invalid.');
        }

        $validated = [];
        foreach ($migrations as $migration) {
            if (
                ! is_string($migration)
                || preg_match('/\A[a-zA-Z0-9_]{1,255}\z/', $migration) !== 1
                || in_array($migration, $validated, true)
            ) {
                throw new RuntimeException('Backup compatibility metadata is invalid.');
            }
            $validated[] = $migration;
        }

        if (
            ! hash_equals($this->schemaHash($validated), $manifest['schema_sha256'])
        ) {
            throw new RuntimeException('Backup compatibility metadata is invalid.');
        }

        $this->assertSupportedMigrations($validated);

        return $validated;
    }
}
