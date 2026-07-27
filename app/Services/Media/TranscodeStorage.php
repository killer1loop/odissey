<?php

namespace App\Services\Media;

use App\Models\TranscodeSession;
use App\Services\Media\Exceptions\TranscodeQuotaExceeded;
use FilesystemIterator;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use Throwable;

class TranscodeStorage
{
    public function prepare(TranscodeSession $session): string
    {
        $directory = $this->directoryForId($session->getKey(), createRoot: true);

        if (File::isDirectory($directory)) {
            $this->delete($session);
        }

        File::ensureDirectoryExists($directory, 0750, true);

        return $directory;
    }

    public function manifestPath(TranscodeSession $session): string
    {
        return $this->sessionDirectory($session).DIRECTORY_SEPARATOR.'index.m3u8';
    }

    public function segmentPattern(TranscodeSession $session): string
    {
        return $this->sessionDirectory($session).DIRECTORY_SEPARATOR.'segment-%05d.ts';
    }

    public function segmentPath(TranscodeSession $session, string $segment): string
    {
        if (preg_match('/\Asegment-\d{5}\.ts\z/', $segment) !== 1) {
            throw new RuntimeException('Invalid HLS segment name.');
        }

        return $this->sessionDirectory($session).DIRECTORY_SEPARATOR.$segment;
    }

    public function hasCompleteOutput(TranscodeSession $session): bool
    {
        $manifestPath = $this->manifestPath($session);

        if (! File::isFile($manifestPath)) {
            return false;
        }

        try {
            $manifest = File::get($manifestPath);
        } catch (Throwable) {
            return false;
        }

        if (
            preg_match(
                '/^segment-\d{5}\.ts$/m',
                $manifest,
                $match,
            ) !== 1
        ) {
            return false;
        }

        return File::isFile(
            $this->sessionDirectory($session)
                .DIRECTORY_SEPARATOR
                .$match[0],
        );
    }

    public function delete(TranscodeSession $session): void
    {
        $this->deleteById($session->getKey());
    }

    public function deleteById(string $sessionId): void
    {
        $directory = $this->directoryForId($sessionId);

        if (is_link($directory)) {
            throw new RuntimeException('The transcode directory is unsafe.');
        }

        if (File::isDirectory($directory) && ! File::deleteDirectory($directory)) {
            throw new RuntimeException('The transcode directory could not be removed.');
        }
    }

    public function bytesUsed(): int
    {
        return $this->directoryBytes($this->root());
    }

    public function bytesFor(TranscodeSession $session): int
    {
        return $this->directoryBytes($this->sessionDirectory($session));
    }

    public function isWithinQuota(): bool
    {
        return $this->bytesUsed() <= $this->quotaBytes();
    }

    public function isWithinStorageLimits(): bool
    {
        if (! $this->isWithinQuota()) {
            return false;
        }

        $freeBytes = @disk_free_space($this->root(create: true));
        if (! is_int($freeBytes) && ! is_float($freeBytes)) {
            return false;
        }

        return $freeBytes - $this->reservedBytes() >= $this->reserveBytes();
    }

    public function assertWithinQuota(): void
    {
        if (! $this->isWithinStorageLimits()) {
            throw new TranscodeQuotaExceeded;
        }
    }

    public function quotaBytes(): int
    {
        return max(1, (int) config('odissey.transcode_max_bytes'));
    }

    public function availableBytes(): int
    {
        $quotaRemaining = max(0, $this->quotaBytes() - $this->bytesUsed());
        $root = $this->root(create: true);
        $freeBytes = @disk_free_space($root);

        if (! is_int($freeBytes) && ! is_float($freeBytes)) {
            return $quotaRemaining;
        }

        $reserve = min($this->reserveBytes(), max(0, (int) $freeBytes));

        return min(
            $quotaRemaining,
            max(
                0,
                (int) $freeBytes - $reserve - $this->reservedBytes(),
            ),
        );
    }

    public function reserveSourceBytes(
        int $maximumBytes,
        ?int $requiredBytes = null,
    ): ?TranscodeReservation {
        $maximumBytes = max(1, $maximumBytes);
        $requiredBytes = $requiredBytes === null
            ? null
            : max(1, $requiredBytes);

        try {
            return Cache::lock(
                'odissey:media:transcode-storage-reservation',
                30,
            )->block(2, function () use (
                $maximumBytes,
                $requiredBytes,
            ): ?TranscodeReservation {
                $availableBytes = min(
                    $maximumBytes,
                    $this->availableBytes(),
                );
                $capacityBytes = $requiredBytes ?? $availableBytes;

                if (
                    $capacityBytes < 1
                    || $capacityBytes > $maximumBytes
                    || $capacityBytes > $availableBytes
                ) {
                    return null;
                }

                $directory = $this->transientDirectory(
                    'sources',
                    create: true,
                );
                $path = $directory
                    .DIRECTORY_SEPARATOR
                    .'reservation-'.Str::lower((string) Str::ulid()).'.reserve';
                $handle = @fopen($path, 'xb');

                if ($handle === false) {
                    throw new TranscodeQuotaExceeded;
                }

                if (
                    ! flock($handle, LOCK_EX | LOCK_NB)
                    || ! ftruncate($handle, $capacityBytes)
                    || ! chmod($path, 0600)
                ) {
                    fclose($handle);
                    @unlink($path);

                    throw new TranscodeQuotaExceeded;
                }

                clearstatcache(true, $path);

                return new TranscodeReservation(
                    $path,
                    $handle,
                    $capacityBytes,
                );
            });
        } catch (LockTimeoutException) {
            return null;
        }
    }

    public function reservedBytes(): int
    {
        $directory = $this->transientDirectory('sources');

        if (! File::isDirectory($directory)) {
            return 0;
        }

        $bytes = 0;

        foreach (File::glob($directory.DIRECTORY_SEPARATOR.'*.reserve') as $path) {
            try {
                if (is_file($path) && ! is_link($path)) {
                    $bytes += max(0, (int) filesize($path));
                }
            } catch (Throwable) {
                // A completed materialization may remove a reservation mid-scan.
            }
        }

        return $bytes;
    }

    private function reserveBytes(): int
    {
        return min(
            100 * 1024 * 1024 * 1024,
            max(0, (int) config(
                'odissey.transcode_min_free_bytes',
                256 * 1024 * 1024,
            )),
        );
    }

    public function transientDirectory(string $name, bool $create = false): string
    {
        if (! in_array($name, ['sources', 'subtitles'], true)) {
            throw new RuntimeException('Invalid transient storage directory.');
        }

        $directory = $this->root($create).DIRECTORY_SEPARATOR.$name;
        if ($create) {
            File::ensureDirectoryExists($directory, 0700, true);
        }

        if (is_link($directory)) {
            throw new RuntimeException('Invalid transient storage directory.');
        }

        return $directory;
    }

    /**
     * @return array<string, int>
     */
    public function sessionDirectories(): array
    {
        $directories = [];
        $root = $this->root();

        if (! File::isDirectory($root)) {
            return $directories;
        }

        foreach (File::directories($root) as $directory) {
            $sessionId = basename($directory);

            if (Str::isUlid($sessionId)) {
                try {
                    $directories[$sessionId] = File::lastModified($directory);
                } catch (Throwable) {
                    // A concurrent cleanup may remove a directory after listing it.
                }
            }
        }

        return $directories;
    }

    private function sessionDirectory(TranscodeSession $session): string
    {
        return $this->directoryForId($session->getKey());
    }

    private function directoryForId(string $sessionId, bool $createRoot = false): string
    {
        if (! Str::isUlid($sessionId)) {
            throw new RuntimeException('Invalid transcode session identifier.');
        }

        return $this->root($createRoot).DIRECTORY_SEPARATOR.$sessionId;
    }

    private function directoryBytes(string $directory): int
    {
        if (! File::isDirectory($directory) || is_link($directory)) {
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
                // Files may disappear while a transcode or cleanup is active.
            }
        }

        return $bytes;
    }

    private function root(bool $create = false): string
    {
        $root = rtrim((string) config('odissey.transcode_path'), DIRECTORY_SEPARATOR);
        $segments = explode(DIRECTORY_SEPARATOR, $root);

        if (
            $root === ''
            || $root === DIRECTORY_SEPARATOR
            || ! str_starts_with($root, DIRECTORY_SEPARATOR)
            || str_contains($root, "\0")
            || str_contains($root, DIRECTORY_SEPARATOR.DIRECTORY_SEPARATOR)
            || in_array('.', $segments, true)
            || in_array('..', $segments, true)
            || is_link($root)
        ) {
            throw new RuntimeException('The transcode path must be a safe absolute path.');
        }

        if ($create) {
            File::ensureDirectoryExists($root, 0750, true);
        }

        if (is_link($root)) {
            throw new RuntimeException('The transcode path must be a safe absolute path.');
        }

        return $root;
    }
}
