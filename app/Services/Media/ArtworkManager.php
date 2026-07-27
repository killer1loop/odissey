<?php

namespace App\Services\Media;

use App\Models\MediaItem;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class ArtworkManager
{
    public function __construct(
        private readonly BoundedMediaDownloader $downloader,
        private readonly MediaAssetStorage $assets,
        private readonly MediaProcessFactory $processes,
    ) {}

    public function populate(MediaItem $item, ?string $localPath): void
    {
        $maximumArtworkBytes = min(
            10 * 1024 * 1024,
            max(1, (int) config('odissey.artwork_max_bytes')),
        );
        $metadata = $item->metadata ?? [];
        foreach (['poster', 'backdrop'] as $kind) {
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
                } catch (\RuntimeException) {
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
                                throw new \RuntimeException('artwork_write_failed');
                            }
                        });
                    } finally {
                        File::delete($temporary);
                    }
                    $metadata[$kind.'_cached'] = true;
                }
            }
        }
        if (empty($metadata['poster_cached']) && $localPath && $item->media_kind === 'video') {
            $directory = $this->directory($item);
            $path = $directory.'/poster.jpg';
            $temporary = $path.'.'.Str::lower((string) Str::ulid()).'.tmp';
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
            try {
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
                            throw new \RuntimeException('artwork_write_failed');
                        }
                    });
                }
            } finally {
                File::delete($temporary);
            }
            $metadata['poster_cached'] = $valid;
        }
        $item->update(['metadata' => $metadata]);
    }

    public function path(MediaItem $item, string $kind): ?string
    {
        if (($item->metadata[$kind.'_cached'] ?? false) !== true) {
            return null;
        }

        $path = $this->root()
            .DIRECTORY_SEPARATOR.$item->getKey()
            .DIRECTORY_SEPARATOR.$kind.'.jpg';

        return File::isFile($path) ? $path : null;
    }

    private function directory(MediaItem $item): string
    {
        $directory = $this->root()
            .DIRECTORY_SEPARATOR.$item->getKey();
        if (is_link($directory)) {
            throw new \RuntimeException('artwork_storage_path_invalid');
        }

        File::ensureDirectoryExists($directory, 0700);
        if (is_link($directory)) {
            throw new \RuntimeException('artwork_storage_path_invalid');
        }

        return $directory;
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
            throw new \RuntimeException('artwork_storage_path_invalid');
        }

        return $root;
    }
}
