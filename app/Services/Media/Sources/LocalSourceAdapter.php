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
        $size = filesize($path);
        $start ??= 0;
        $end = min($end ?? ($size - 1), $size - 1);
        $handle = fopen($path, 'rb');
        fseek($handle, $start);

        return new SourceResponse($handle, ($start > 0 || $end < $size - 1) ? 206 : 200, $size, mime_content_type($path) ?: 'application/octet-stream', "bytes {$start}-{$end}/{$size}");
    }

    public function localPath(MediaSource $source, string $locator): ?string
    {
        return $this->resolve($source, $locator);
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
        if ($path === false || ! is_file($path) || ! str_starts_with($path, $root.DIRECTORY_SEPARATOR)) {
            throw new RuntimeException('source_object_unavailable');
        }

        return $path;
    }
}
