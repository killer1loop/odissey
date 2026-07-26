<?php

namespace App\Services\Media\Sources;

use App\Models\MediaSource;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;

class LocalSourceAdapter implements MediaSourceAdapter
{
    public function objects(MediaSource $source): iterable
    {
        $root = $this->root($source);
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if (! $file->isFile() || $file->isLink()) {
                continue;
            }

            $path = $file->getRealPath();
            if ($path === false || ! str_starts_with($path, $root.DIRECTORY_SEPARATOR)) {
                continue;
            }

            yield new SourceObject(
                locator: substr($path, strlen($root) + 1),
                path: substr($path, strlen($root) + 1),
                size: $file->getSize(),
                etag: hash('sha256', $file->getInode().':'.$file->getSize().':'.$file->getMTime()),
                modifiedAt: $file->getMTime(),
            );
        }
    }

    public function capabilities(MediaSource $source): array
    {
        $this->root($source);

        return ['range' => true, 'seekable' => true, 'read_only' => true];
    }

    public function open(MediaSource $source, string $locator, ?int $start, ?int $end): SourceResponse
    {
        $path = $this->resolve($source, $locator);
        $before = lstat($path);
        if (! is_array($before) || is_link($path)) {
            throw new RuntimeException('source_object_unavailable');
        }

        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            throw new RuntimeException('source_object_unavailable');
        }

        $opened = fstat($handle);
        if (
            ! is_array($opened)
            || ($before['dev'] ?? null) !== ($opened['dev'] ?? null)
            || ($before['ino'] ?? null) !== ($opened['ino'] ?? null)
            || (($opened['mode'] ?? 0) & 0170000) !== 0100000
        ) {
            fclose($handle);

            throw new RuntimeException('source_object_unavailable');
        }

        $size = (int) ($opened['size'] ?? 0);
        if ($size < 1) {
            fclose($handle);

            throw new RuntimeException('source_object_unavailable');
        }

        $rangeRequested = $start !== null;
        $start ??= 0;
        if ($start < 0 || $start >= $size) {
            fclose($handle);

            throw new RuntimeException('source_range_invalid');
        }
        $end = min($end ?? ($size - 1), $size - 1);
        if ($end < $start || fseek($handle, $start) !== 0) {
            fclose($handle);

            throw new RuntimeException('source_range_invalid');
        }

        $status = $rangeRequested ? 206 : 200;

        return new SourceResponse(
            $handle,
            $status,
            $end - $start + 1,
            'application/octet-stream',
            $status === 206 ? "bytes {$start}-{$end}/{$size}" : null,
        );
    }

    public function localPath(MediaSource $source, string $locator): ?string
    {
        // Consumers must use open(), which binds validation to the opened
        // inode. Returning a pathname would let a writable NAS replace it
        // between validation and FFmpeg/FFprobe opening it.
        return null;
    }

    private function root(MediaSource $source): string
    {
        $configured = $source->configuration['path'] ?? '';
        $root = realpath($configured);
        $allowed = array_filter(array_map('realpath', config('odissey.local_source_roots', ['/media'])));
        if ($root === false || ! is_dir($root) || ! collect($allowed)->contains(fn ($path) => $root === $path || str_starts_with($root, $path.DIRECTORY_SEPARATOR))) {
            throw new RuntimeException('source_unavailable');
        }

        return $root;
    }

    private function resolve(MediaSource $source, string $locator): string
    {
        $root = $this->root($source);
        $path = realpath($root.DIRECTORY_SEPARATOR.ltrim($locator, '/'));
        if (
            $path === false
            || ! is_file($path)
            || is_link($root.DIRECTORY_SEPARATOR.ltrim($locator, '/'))
            || ! str_starts_with($path, $root.DIRECTORY_SEPARATOR)
        ) {
            throw new RuntimeException('source_object_unavailable');
        }

        return $path;
    }
}
