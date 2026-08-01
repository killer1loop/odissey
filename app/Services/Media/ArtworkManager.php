<?php

namespace App\Services\Media;

use App\Models\MediaItem;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class ArtworkManager
{
    private const ARTWORK_KINDS = ['poster', 'backdrop'];

    public const MIN_VARIANT_DIMENSION = 32;

    public const MAX_VARIANT_DIMENSION = 3840;

    public const MAX_VARIANT_PIXELS = 8_294_400;

    public const VARIANT_LONG_EDGE_BUCKETS = [
        32,
        64,
        96,
        128,
        160,
        240,
        320,
        480,
        720,
        1080,
        1920,
        3840,
    ];

    public function __construct(
        private readonly BoundedMediaDownloader $downloader,
        private readonly MediaAssetStorage $assets,
        private readonly MediaProcessFactory $processes,
        private readonly ArtworkConcurrencyGate $concurrency,
    ) {}

    public function populate(MediaItem $item, ?string $localPath): void
    {
        $maximumArtworkBytes = min(
            10 * 1024 * 1024,
            max(1, (int) config('odissey.artwork_max_bytes')),
        );
        $metadata = $item->metadata ?? [];
        foreach (['poster', 'backdrop'] as $kind) {
            if ($this->path($item, $kind) !== null) {
                $metadata[$kind.'_cached'] = true;

                continue;
            }

            $url = $metadata[$kind.'_url'] ?? null;
            if ($url) {
                $host = strtolower((string) parse_url($url, PHP_URL_HOST));
                if (! in_array($host, ['image.tmdb.org', 'static.tvmaze.com'], true)) {
                    continue;
                }
                try {
                    $download = $this->downloader->download(
                        url: $url,
                        maxBytes: $maximumArtworkBytes,
                        allowedHost: fn (string $candidate): bool => in_array(
                            $candidate,
                            ['image.tmdb.org', 'static.tvmaze.com'],
                            true,
                        ),
                        maxRedirects: 2,
                        timeoutSeconds: 15,
                    );
                } catch (RuntimeException) {
                    continue;
                }
                $imageInfo = $download['body'] === ''
                    ? false
                    : @getimagesizefromstring($download['body']);
                if (
                    $download['body'] !== ''
                    && str_starts_with(strtolower($download['content_type']), 'image/jpeg')
                    && is_array($imageInfo)
                    && ($imageInfo[2] ?? null) === IMAGETYPE_JPEG
                ) {
                    $directory = $this->directory($item);
                    $path = $directory.'/'.$kind.'.jpg';
                    $temporary = $path.'.'.Str::lower((string) Str::ulid()).'.tmp';
                    try {
                        if (
                            File::put($temporary, $download['body'], true)
                            !== strlen($download['body'])
                        ) {
                            continue;
                        }

                        $this->assets->synchronized(function () use (
                            $download,
                            $path,
                            $temporary,
                        ): void {
                            $this->assets->assertCanPublish(
                                strlen($download['body']),
                                [$temporary],
                                $path,
                            );

                            if (
                                (File::exists($path) && ! File::delete($path))
                                || ! File::move($temporary, $path)
                                || ! chmod($path, 0600)
                            ) {
                                throw new RuntimeException('artwork_write_failed');
                            }
                            $this->clearVariants(dirname($path));
                        });
                    } finally {
                        File::delete($temporary);
                    }
                    $metadata[$kind.'_cached'] = true;
                }
            }
        }
        if (
            $this->path($item->forceFill(['metadata' => $metadata]), 'poster') === null
            && $localPath
            && $item->media_kind === 'video'
        ) {
            $directory = $this->directory($item);
            $path = $directory.'/poster.jpg';
            $temporary = $path.'.'.Str::lower((string) Str::ulid()).'.tmp';
            $processLock = $this->concurrency->acquire(
                $this->generationLeaseSeconds(),
            );
            $valid = false;
            try {
                if ($processLock !== null) {
                    $process = $this->processes->make([
                        config('odissey.ffmpeg_binary', 'ffmpeg'), '-hide_banner', '-loglevel', 'error',
                        '-nostdin', '-y',
                        '-max_alloc', (string) min(
                            1024 * 1024 * 1024,
                            max(16 * 1024 * 1024, (int) config(
                                'odissey.ffmpeg_max_alloc_bytes',
                                256 * 1024 * 1024,
                            )),
                        ),
                        '-max_pixels', (string) min(
                            7680 * 4320,
                            max(1920 * 1080, (int) config(
                                'odissey.ffmpeg_max_pixels',
                                7680 * 4320,
                            )),
                        ),
                        '-threads', (string) min(
                            16,
                            max(1, (int) config('odissey.ffmpeg_threads', 2)),
                        ),
                        '-ss', '00:00:05',
                        '-protocol_whitelist', 'file,pipe',
                        '-i', $localPath,
                        '-frames:v', '1', '-vf', 'scale=480:-2',
                        '-c:v', 'mjpeg', '-f', 'image2', $temporary,
                    ], 30);
                    $process->run();
                    $imageInfo = File::isFile($temporary) ? @getimagesize($temporary) : false;
                    $valid = $process->isSuccessful()
                        && File::isFile($temporary)
                        && File::size($temporary) > 0
                        && File::size($temporary) <= $maximumArtworkBytes
                        && is_array($imageInfo)
                        && ($imageInfo[2] ?? null) === IMAGETYPE_JPEG;
                    if ($valid) {
                        $this->assets->synchronized(function () use (
                            $path,
                            $temporary,
                        ): void {
                            $this->assets->assertCanPublish(
                                File::size($temporary),
                                [$temporary],
                                $path,
                            );
                            File::delete($path);

                            if (
                                ! File::move($temporary, $path)
                                || ! chmod($path, 0600)
                            ) {
                                throw new RuntimeException('artwork_write_failed');
                            }
                            $this->clearVariants(dirname($path));
                        });
                    }
                }
            } finally {
                File::delete($temporary);
                try {
                    $processLock?->release();
                } catch (Throwable) {
                    //
                }
            }
            $metadata['poster_cached'] = $valid;
        }
        $item->update(['metadata' => $metadata]);
    }

    public function path(MediaItem $item, string $kind): ?string
    {
        if (! in_array($kind, self::ARTWORK_KINDS, true)) {
            return null;
        }
        if (($item->metadata[$kind.'_cached'] ?? null) === false) {
            return null;
        }

        $key = (string) $item->getKey();
        if (
            $key === ''
            || str_contains($key, DIRECTORY_SEPARATOR)
            || str_contains($key, "\0")
        ) {
            return null;
        }

        $directory = $this->root().DIRECTORY_SEPARATOR.$key;
        if (is_link($directory) || ! File::isDirectory($directory)) {
            return null;
        }

        $path = $directory.DIRECTORY_SEPARATOR.$kind.'.jpg';

        return File::isFile($path) && ! is_link($path) ? $path : null;
    }

    public function variantPath(
        MediaItem $item,
        string $kind,
        ?int $width,
        ?int $height,
    ): ?string {
        $source = $this->path($item, $kind);
        if ($source === null || ($width === null && $height === null)) {
            return $source;
        }
        $this->assertVariantRequest($width, $height);

        $sourceInfo = @getimagesize($source);
        if (
            ! is_array($sourceInfo)
            || ($sourceInfo[2] ?? null) !== IMAGETYPE_JPEG
            || ($sourceInfo[0] ?? 0) < 1
            || ($sourceInfo[1] ?? 0) < 1
        ) {
            return $source;
        }
        [$targetWidth, $targetHeight] = $this->variantDimensions(
            (int) $sourceInfo[0],
            (int) $sourceInfo[1],
            $width,
            $height,
        );
        if (
            $targetWidth === (int) $sourceInfo[0]
            && $targetHeight === (int) $sourceInfo[1]
        ) {
            return $source;
        }

        $sourceHash = hash_file('sha256', $source);
        if (! is_string($sourceHash) || $sourceHash === '') {
            return $source;
        }
        $directory = $this->variantDirectory($item);
        $path = $directory.DIRECTORY_SEPARATOR.sprintf(
            '%s-%dx%d-%s.jpg',
            $kind,
            $targetWidth,
            $targetHeight,
            substr($sourceHash, 0, 16),
        );
        if (is_link($path)) {
            throw new RuntimeException('artwork_storage_path_invalid');
        }
        if (File::isFile($path)) {
            return $path;
        }

        $leaseSeconds = $this->generationLeaseSeconds();
        $variantLock = $this->concurrency->acquireVariant(
            implode('|', [
                (string) $item->getKey(),
                $kind,
                (string) $targetWidth,
                (string) $targetHeight,
                $sourceHash,
            ]),
            $leaseSeconds,
        );
        if ($variantLock === null) {
            return $source;
        }
        $processLock = null;
        try {
            if (File::isFile($path)) {
                return $path;
            }
            $this->pruneStaleVariants(
                $directory,
                $kind,
                substr($sourceHash, 0, 16),
            );
            $processLock = $this->concurrency->acquire($leaseSeconds);
            if ($processLock === null) {
                return $source;
            }

            $temporary = $path.'.'.Str::lower((string) Str::ulid()).'.tmp';
            $process = $this->processes->make([
                config('odissey.ffmpeg_binary', 'ffmpeg'),
                '-hide_banner',
                '-loglevel',
                'error',
                '-nostdin',
                '-y',
                '-max_alloc',
                (string) min(
                    1024 * 1024 * 1024,
                    max(
                        16 * 1024 * 1024,
                        (int) config(
                            'odissey.ffmpeg_max_alloc_bytes',
                            256 * 1024 * 1024,
                        ),
                    ),
                ),
                '-max_pixels',
                (string) min(
                    7680 * 4320,
                    max(
                        1920 * 1080,
                        (int) config(
                            'odissey.ffmpeg_max_pixels',
                            7680 * 4320,
                        ),
                    ),
                ),
                '-threads',
                (string) min(
                    16,
                    max(1, (int) config('odissey.ffmpeg_threads', 2)),
                ),
                '-protocol_whitelist',
                'file,pipe',
                '-i',
                $source,
                '-frames:v',
                '1',
                '-vf',
                sprintf('scale=%d:%d', $targetWidth, $targetHeight),
                '-c:v',
                'mjpeg',
                '-q:v',
                '3',
                '-f',
                'image2',
                $temporary,
            ], 30);

            try {
                $process->run();
                $variantInfo = File::isFile($temporary)
                    ? @getimagesize($temporary)
                    : false;
                $valid = $process->isSuccessful()
                    && File::isFile($temporary)
                    && ! is_link($temporary)
                    && File::size($temporary) > 0
                    && File::size($temporary) <= min(
                        10 * 1024 * 1024,
                        max(
                            1,
                            (int) config('odissey.artwork_max_bytes'),
                        ),
                    )
                    && is_array($variantInfo)
                    && ($variantInfo[2] ?? null) === IMAGETYPE_JPEG
                    && ($variantInfo[0] ?? null) === $targetWidth
                    && ($variantInfo[1] ?? null) === $targetHeight;
                if (! $valid) {
                    return $source;
                }

                return $this->assets->synchronized(function () use (
                    $path,
                    $temporary,
                ): string {
                    if (is_link($path)) {
                        throw new RuntimeException(
                            'artwork_storage_path_invalid',
                        );
                    }
                    if (File::isFile($path)) {
                        return $path;
                    }
                    $this->assets->assertCanPublish(
                        File::size($temporary),
                        [$temporary],
                        $path,
                    );
                    if (
                        ! File::move($temporary, $path)
                        || ! chmod($path, 0600)
                    ) {
                        throw new RuntimeException('artwork_write_failed');
                    }

                    return $path;
                });
            } finally {
                File::delete($temporary);
            }
        } finally {
            try {
                $processLock?->release();
            } catch (Throwable) {
                //
            }
            try {
                $variantLock->release();
            } catch (Throwable) {
                //
            }
        }
    }

    private function directory(MediaItem $item): string
    {
        $directory = $this->root()
            .DIRECTORY_SEPARATOR.$item->getKey();
        if (is_link($directory)) {
            throw new RuntimeException('artwork_storage_path_invalid');
        }

        File::ensureDirectoryExists($directory, 0700);
        if (is_link($directory)) {
            throw new RuntimeException('artwork_storage_path_invalid');
        }

        return $directory;
    }

    private function variantDirectory(MediaItem $item): string
    {
        $directory = $this->directory($item)
            .DIRECTORY_SEPARATOR.'variants';
        if (is_link($directory)) {
            throw new RuntimeException('artwork_storage_path_invalid');
        }
        File::ensureDirectoryExists($directory, 0700);
        if (is_link($directory)) {
            throw new RuntimeException('artwork_storage_path_invalid');
        }

        return $directory;
    }

    private function assertVariantRequest(
        ?int $width,
        ?int $height,
    ): void {
        foreach ([$width, $height] as $dimension) {
            if (
                $dimension !== null
                && (
                    $dimension < self::MIN_VARIANT_DIMENSION
                    || $dimension > self::MAX_VARIANT_DIMENSION
                )
            ) {
                throw new RuntimeException(
                    'artwork_variant_dimensions_invalid',
                );
            }
        }
        if (
            $width !== null
            && $height !== null
            && $width * $height > self::MAX_VARIANT_PIXELS
        ) {
            throw new RuntimeException('artwork_variant_pixels_exceeded');
        }
    }

    /**
     * @return array{int, int}
     */
    private function variantDimensions(
        int $sourceWidth,
        int $sourceHeight,
        ?int $width,
        ?int $height,
    ): array {
        $ratios = [
            1.0,
            self::MAX_VARIANT_DIMENSION / $sourceWidth,
            self::MAX_VARIANT_DIMENSION / $sourceHeight,
            sqrt(
                self::MAX_VARIANT_PIXELS
                / ($sourceWidth * $sourceHeight),
            ),
        ];
        if ($width !== null) {
            $ratios[] = $width / $sourceWidth;
        }
        if ($height !== null) {
            $ratios[] = $height / $sourceHeight;
        }
        $ratio = min($ratios);
        $idealLongEdge = (int) floor(max(
            $sourceWidth * $ratio,
            $sourceHeight * $ratio,
        ));
        $bucket = self::VARIANT_LONG_EDGE_BUCKETS[0];
        foreach (self::VARIANT_LONG_EDGE_BUCKETS as $candidate) {
            if ($candidate > $idealLongEdge) {
                break;
            }
            $bucket = $candidate;
        }
        $ratio = min(
            $ratio,
            $bucket / max($sourceWidth, $sourceHeight),
        );

        return [
            max(1, (int) floor($sourceWidth * $ratio)),
            max(1, (int) floor($sourceHeight * $ratio)),
        ];
    }

    private function clearVariants(string $itemDirectory): void
    {
        $directory = $itemDirectory.DIRECTORY_SEPARATOR.'variants';
        if (is_link($directory)) {
            throw new RuntimeException('artwork_storage_path_invalid');
        }
        if (
            File::isDirectory($directory)
            && ! File::deleteDirectory($directory)
        ) {
            throw new RuntimeException('artwork_write_failed');
        }
    }

    private function pruneStaleVariants(
        string $directory,
        string $kind,
        string $sourceHash,
    ): void {
        $stale = $this->staleVariants($directory, $kind, $sourceHash);
        if ($stale === []) {
            return;
        }

        $this->assets->synchronized(function () use (
            $directory,
            $kind,
            $sourceHash,
        ): void {
            foreach (
                $this->staleVariants($directory, $kind, $sourceHash) as $path
            ) {
                if (! File::delete($path)) {
                    throw new RuntimeException('artwork_write_failed');
                }
            }
        });
    }

    /**
     * @return list<string>
     */
    private function staleVariants(
        string $directory,
        string $kind,
        string $sourceHash,
    ): array {
        $stale = [];
        foreach (File::files($directory) as $file) {
            $path = $file->getPathname();
            if (
                is_link($path)
                || preg_match(
                    '/^'.preg_quote($kind, '/')
                        .'-\d+x\d+-([a-f0-9]{16})\.jpg$/',
                    $file->getFilename(),
                    $matches,
                ) !== 1
                || hash_equals($sourceHash, $matches[1])
            ) {
                continue;
            }
            $stale[] = $path;
        }

        return $stale;
    }

    private function root(): string
    {
        $root = rtrim(
            (string) config('odissey.artwork_path'),
            DIRECTORY_SEPARATOR,
        );

        if (
            $root === ''
            || $root === DIRECTORY_SEPARATOR
            || ! str_starts_with($root, DIRECTORY_SEPARATOR)
            || is_link($root)
        ) {
            throw new RuntimeException('artwork_storage_path_invalid');
        }

        return $root;
    }

    private function generationLeaseSeconds(): int
    {
        return min(
            120,
            max(
                31,
                (int) config(
                    'odissey.artwork_generation_lease_seconds',
                    45,
                ),
            ),
        );
    }
}
