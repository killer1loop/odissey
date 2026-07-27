<?php

namespace App\Services\Media;

use FilesystemIterator;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use Throwable;

class MediaAssetStorage
{
    /**
     * Serialize the final quota check and publication across queue workers.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public function synchronized(callable $callback): mixed
    {
        $lock = null;

        try {
            $lock = Cache::lock('odissey:media:asset-storage', 300);
            $lock->block(min(
                30,
                max(1, (int) config(
                    'odissey.media_asset_lock_wait_seconds',
                    5,
                )),
            ));
        } catch (LockTimeoutException) {
            throw new RuntimeException('media_asset_storage_busy');
        } catch (Throwable $exception) {
            throw new RuntimeException(
                'media_asset_storage_busy',
                0,
                $exception,
            );
        }

        try {
            return $callback();
        } finally {
            if ($lock instanceof Lock) {
                try {
                    $lock->release();
                } catch (Throwable) {
                    // The finite lease still prevents a permanent deadlock.
                }
            }
        }
    }

    public function assertCanStore(int $incomingBytes, ?string $replacedPath = null): void
    {
        $incomingBytes = max(0, $incomingBytes);
        $replacedBytes = is_string($replacedPath) && is_file($replacedPath)
            ? max(0, (int) filesize($replacedPath))
            : 0;

        if ($this->bytesUsed() - $replacedBytes + $incomingBytes > $this->quotaBytes()) {
            throw new RuntimeException('media_asset_storage_quota_exceeded');
        }

        if (! $this->hasFreeSpaceFor($incomingBytes, $replacedPath)) {
            throw new RuntimeException('media_asset_storage_capacity_exhausted');
        }
    }

    /**
     * Validate the final state when the incoming file and other staging files
     * are already present below the managed asset roots.
     *
     * @param  list<string>  $stagedPaths
     */
    public function assertCanPublish(
        int $incomingBytes,
        array $stagedPaths,
        string $replacedPath,
    ): void {
        $excludedBytes = 0;

        foreach (array_unique($stagedPaths) as $path) {
            if (is_file($path) && ! is_link($path)) {
                $excludedBytes += max(0, (int) filesize($path));
            }
        }

        $replacedBytes = is_file($replacedPath) && ! is_link($replacedPath)
            ? max(0, (int) filesize($replacedPath))
            : 0;

        if (
            $this->bytesUsed()
                - $excludedBytes
                - $replacedBytes
                + max(0, $incomingBytes)
            > $this->quotaBytes()
        ) {
            throw new RuntimeException('media_asset_storage_quota_exceeded');
        }

        if (! $this->hasFreeSpaceFor(0, $replacedPath)) {
            throw new RuntimeException('media_asset_storage_capacity_exhausted');
        }
    }

    public function isWithinQuota(): bool
    {
        return $this->bytesUsed() <= $this->quotaBytes()
            && $this->hasFreeSpaceFor(0, null);
    }

    public function bytesUsed(): int
    {
        return $this->directoryBytes((string) config('odissey.artwork_path'))
            + $this->directoryBytes((string) config('odissey.caption_path'));
    }

    private function quotaBytes(): int
    {
        return min(
            100 * 1024 * 1024 * 1024,
            max(1, (int) config(
                'odissey.media_asset_max_bytes',
                10 * 1024 * 1024 * 1024,
            )),
        );
    }

    private function hasFreeSpaceFor(
        int $incomingBytes,
        ?string $targetPath,
    ): bool {
        $paths = $targetPath === null
            ? [
                (string) config('odissey.artwork_path'),
                (string) config('odissey.caption_path'),
            ]
            : [$targetPath];
        $checked = [];

        foreach ($paths as $path) {
            $directory = $this->existingDirectory($path);
            if ($directory === null || isset($checked[$directory])) {
                continue;
            }
            $checked[$directory] = true;

            $freeBytes = @disk_free_space($directory);
            if (! is_int($freeBytes) && ! is_float($freeBytes)) {
                return false;
            }

            $reserve = min(
                100 * 1024 * 1024 * 1024,
                max(0, (int) config(
                    'odissey.media_asset_min_free_bytes',
                    256 * 1024 * 1024,
                )),
            );

            if ((int) $freeBytes - max(0, $incomingBytes) < $reserve) {
                return false;
            }
        }

        return $checked !== [];
    }

    private function existingDirectory(string $path): ?string
    {
        if ($path === '' || ! str_starts_with($path, DIRECTORY_SEPARATOR)) {
            return null;
        }

        $directory = is_dir($path) ? $path : dirname($path);

        while (
            $directory !== DIRECTORY_SEPARATOR
            && ! is_dir($directory)
        ) {
            $directory = dirname($directory);
        }

        return is_dir($directory) ? $directory : null;
    }

    private function directoryBytes(string $directory): int
    {
        $directory = rtrim($directory, DIRECTORY_SEPARATOR);
        if (
            $directory === ''
            || $directory === DIRECTORY_SEPARATOR
            || ! str_starts_with($directory, DIRECTORY_SEPARATOR)
            || is_link($directory)
            || ! is_dir($directory)
        ) {
            return 0;
        }

        $bytes = 0;
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            try {
                if ($file->isFile() && ! $file->isLink()) {
                    $bytes += $file->getSize();
                }
            } catch (Throwable) {
                // Concurrent cleanup may remove an asset while it is counted.
            }
        }

        return $bytes;
    }
}
