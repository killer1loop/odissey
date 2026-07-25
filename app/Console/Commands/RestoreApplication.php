<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use RuntimeException;
use ZipArchive;

class RestoreApplication extends Command
{
    protected $signature = 'odissey:restore {path : Backup .zip path} {--force : Confirm replacement of current database and key}';

    protected $description = 'Validate and restore an Odissey database and application key backup';

    public function handle(): int
    {
        if (! $this->option('force')) {
            $this->error('Restore requires --force.');

            return self::FAILURE;
        }
        $zip = new ZipArchive;
        if ($zip->open($this->argument('path')) !== true) {
            throw new RuntimeException('Backup cannot be opened.');
        }
        $manifest = json_decode($zip->getFromName('manifest.json') ?: '', true);
        $database = $zip->getFromName('database.sqlite');
        $key = trim($zip->getFromName('app.key') ?: '');
        $zip->close();
        if (($manifest['format'] ?? null) !== 1 || ! is_string($database) || ! hash_equals($manifest['database_sha256'] ?? '', hash('sha256', $database)) || ! str_starts_with($key, 'base64:')) {
            throw new RuntimeException('Backup validation failed.');
        }
        $staged = tempnam(sys_get_temp_dir(), 'odissey-restore-');
        File::put($staged, $database);
        $check = new \SQLite3($staged, SQLITE3_OPEN_READONLY);
        if ($check->querySingle('PRAGMA integrity_check') !== 'ok') {
            throw new RuntimeException('Backup database integrity check failed.');
        }
        $check->close();
        $dbPath = config('database.connections.sqlite.database');
        DB::disconnect('sqlite');
        File::copy($dbPath, $dbPath.'.before-restore');
        File::move($staged, $dbPath);
        $keyPath = env('ODISSEY_APP_KEY_FILE', rtrim(config('odissey.data_path'), '/').'/app.key');
        File::put($keyPath, $key."\n");
        chmod($keyPath, 0600);
        $this->warn('Restore complete. Restart the container before serving requests. Previous database: '.$dbPath.'.before-restore');

        return self::SUCCESS;
    }
}
