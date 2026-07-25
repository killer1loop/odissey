<?php

namespace App\Services\Media;

use App\Models\TranscodeSession;
use App\Services\Media\Exceptions\TranscodeQuotaExceeded;
use FilesystemIterator;
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
        return File::isFile($this->manifestPath($session))
            && File::glob($this->sessionDirectory($session).DIRECTORY_SEPARATOR.'segment-*.ts') !== [];
    }

    public function delete(TranscodeSession $session): void
    {
        $this->deleteById($session->getKey());
    }

    public function deleteById(string $sessionId): void
    {
        $directory = $this->directoryForId($sessionId);

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

    public function assertWithinQuota(): void
    {
        if (! $this->isWithinQuota()) {
            throw new TranscodeQuotaExceeded;
        }
    }

    public function quotaBytes(): int
    {
        return max(1, (int) config('odissey.transcode_max_bytes'));
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
        if (! File::isDirectory($directory)) {
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

        if ($root === '' || $root === DIRECTORY_SEPARATOR || ! str_starts_with($root, DIRECTORY_SEPARATOR)) {
            throw new RuntimeException('The transcode path must be a safe absolute path.');
        }

        if ($create) {
            File::ensureDirectoryExists($root, 0750, true);
        }

        return $root;
    }
}
