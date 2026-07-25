<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use RuntimeException;
use ZipArchive;

class BackupApplication extends Command
{
    protected $signature = 'odissey:backup {path : Destination .zip path}';

    protected $description = 'Create a portable encrypted-settings-capable database and key backup';

    public function handle(): int
    {
        $destination = $this->argument('path');
        if (! str_ends_with(strtolower($destination), '.zip')) {
            $destination .= '.zip';
        }
        File::ensureDirectoryExists(dirname($destination), 0700);
        $temporary = tempnam(sys_get_temp_dir(), 'odissey-db-');
        File::delete($temporary);
        DB::statement('PRAGMA wal_checkpoint(FULL)');
        DB::statement("VACUUM INTO '".str_replace("'", "''", $temporary)."'");
        $keyPath = env('ODISSEY_APP_KEY_FILE', rtrim(config('odissey.data_path'), '/').'/app.key');
        $key = File::isFile($keyPath) ? trim(File::get($keyPath)) : config('app.key');
        if (! is_string($key) || $key === '') {
            throw new RuntimeException('Application key is unavailable.');
        }
        $zip = new ZipArchive;
        if ($zip->open($destination, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Backup destination cannot be opened.');
        }
        $zip->addFile($temporary, 'database.sqlite');
        $zip->addFromString('app.key', $key."\n");
        $zip->addFromString('manifest.json', json_encode(['format' => 1, 'created_at' => now()->toIso8601String(), 'database_sha256' => hash_file('sha256', $temporary)], JSON_THROW_ON_ERROR));
        $zip->close();
        File::delete($temporary);
        chmod($destination, 0600);
        $this->info('Backup created at '.$destination);

        return self::SUCCESS;
    }
}
