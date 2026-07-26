<?php

namespace App\Services\Media\Captions;

use App\Models\MediaItem;
use App\Services\Media\Exceptions\TranscodeQuotaExceeded;
use App\Services\Media\FfmpegRunner;
use App\Services\Media\MediaAssetStorage;
use App\Services\Media\TranscodeReservation;
use App\Services\Media\TranscodeStorage;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;
use ZipArchive;

class CaptionStorage
{
    public function __construct(
        private readonly MediaAssetStorage $assets,
        private readonly FfmpegRunner $runner,
        private readonly TranscodeStorage $transcodes,
    ) {}

    public function store(MediaItem $item, CaptionCandidate $candidate, string $bytes): string
    {
        $maximumBytes = min(
            8 * 1024 * 1024,
            max(1, (int) config('odissey.caption_max_bytes')),
        );
        if (strlen($bytes) === 0 || strlen($bytes) > $maximumBytes) {
            throw new RuntimeException('caption_size_invalid');
        }
        $root = rtrim(
            (string) config('odissey.caption_path'),
            DIRECTORY_SEPARATOR,
        );
        if (
            $root === ''
            || $root === DIRECTORY_SEPARATOR
            || ! str_starts_with($root, DIRECTORY_SEPARATOR)
            || is_link($root)
        ) {
            throw new RuntimeException('caption_storage_path_invalid');
        }

        $directory = $root.DIRECTORY_SEPARATOR.$item->id;
        File::ensureDirectoryExists($directory, 0700);
        if (is_link($directory)) {
            throw new RuntimeException('caption_storage_path_invalid');
        }
        $stem = preg_replace('/[^a-z0-9-]/', '', strtolower($candidate->provider.'-'.$candidate->language.'-'.$candidate->externalId));
        if (! is_string($stem) || $stem === '') {
            throw new RuntimeException('caption_identifier_invalid');
        }
        $staging = $this->transcodes->transientDirectory(
            'subtitles',
            create: true,
        ).'/caption-'.Str::lower((string) Str::ulid());
        $input = $staging.'.input';
        $output = $directory.'/'.$stem.'.vtt';
        $temporaryOutput = $staging.'.tmp';
        $publishTemporary = $output
            .'.'.Str::lower((string) Str::ulid()).'.tmp';
        $archive = str_starts_with($bytes, "PK\x03\x04");
        $webVtt = str_starts_with(
            ltrim(substr($bytes, 0, 32)),
            'WEBVTT',
        );
        $requiredStagingBytes = strlen($bytes)
            + ($webVtt ? 0 : $maximumBytes)
            + ($archive ? $maximumBytes : 0);
        $reservation = $this->transcodes->reserveSourceBytes(
            $requiredStagingBytes,
            $requiredStagingBytes,
        );
        if ($reservation === null) {
            throw new RuntimeException('caption_storage_quota_exceeded');
        }
        $this->assets->assertCanStore(1, $output);
        $source = $input;

        try {
            if (File::put($input, $bytes, true) !== strlen($bytes)) {
                throw new RuntimeException('caption_write_failed');
            }
            $reservation->consume(strlen($bytes));

            if ($archive) {
                $zip = new ZipArchive;
                if ($zip->open($input) !== true) {
                    throw new RuntimeException('caption_archive_invalid');
                }
                try {
                    $source = '';
                    for ($i = 0; $i < min($zip->numFiles, 100); $i++) {
                        $name = $zip->getNameIndex($i);
                        $stat = $zip->statIndex($i);
                        $size = is_array($stat) ? ($stat['size'] ?? PHP_INT_MAX) : PHP_INT_MAX;
                        $compressed = is_array($stat) ? ($stat['comp_size'] ?? 0) : 0;
                        if (
                            is_string($name)
                            && $size > 0
                            && $size <= $maximumBytes
                            && ($compressed > 0 ? $size / $compressed <= 1000 : $size <= 1024)
                            && preg_match('/\.(srt|vtt|ass|ssa)$/i', basename($name))
                            && ! str_contains($name, '..')
                        ) {
                            $extension = strtolower(
                                pathinfo($name, PATHINFO_EXTENSION),
                            );
                            $source = $staging.'.'.$extension;
                            $this->extractArchiveEntry(
                                $zip,
                                $i,
                                $source,
                                (int) $size,
                                $maximumBytes,
                                $reservation,
                            );
                            break;
                        }
                    }
                } finally {
                    $zip->close();
                }
                if ($source === '') {
                    throw new RuntimeException('caption_archive_empty');
                }

                File::delete($input);
            }

            if (
                strtolower(pathinfo($source, PATHINFO_EXTENSION)) === 'vtt'
                || $this->looksLikeWebVtt($source)
            ) {
                if (! File::move($source, $temporaryOutput)) {
                    throw new RuntimeException('caption_write_failed');
                }
            } else {
                $observedOutputBytes = 0;
                $this->runner->run([
                    config('odissey.ffmpeg_binary', 'ffmpeg'),
                    '-hide_banner', '-loglevel', 'error', '-nostdin', '-y',
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
                    '-protocol_whitelist', 'file,pipe',
                    '-i', $source,
                    '-f', 'webvtt',
                    $temporaryOutput,
                ], 30, function () use (
                    &$observedOutputBytes,
                    $maximumBytes,
                    $reservation,
                    $temporaryOutput,
                ): bool {
                    $this->consumeOutputGrowth(
                        $temporaryOutput,
                        $observedOutputBytes,
                        $maximumBytes,
                        $reservation,
                    );

                    return $this->transcodes->isWithinStorageLimits();
                });
                $this->consumeOutputGrowth(
                    $temporaryOutput,
                    $observedOutputBytes,
                    $maximumBytes,
                    $reservation,
                );
            }

            File::delete($input);
            if ($source !== $input && $source !== $temporaryOutput) {
                File::delete($source);
            }

            if (
                ! File::isFile($temporaryOutput)
                || File::size($temporaryOutput) < 1
                || File::size($temporaryOutput) > $maximumBytes
                || ! $this->looksLikeWebVtt($temporaryOutput)
            ) {
                throw new RuntimeException('caption_output_invalid');
            }

            $this->assets->synchronized(function () use (

                $output,
                $publishTemporary,
                $temporaryOutput,
            ): void {
                if (
                    ! File::copy($temporaryOutput, $publishTemporary)
                    || ! chmod($publishTemporary, 0600)
                ) {
                    throw new RuntimeException('caption_write_failed');
                }
                $this->assets->assertCanPublish(
                    File::size($temporaryOutput),
                    [$publishTemporary],
                    $output,
                );
                File::delete($output);
                if (
                    ! File::move($publishTemporary, $output)
                    || ! chmod($output, 0600)
                ) {
                    throw new RuntimeException('caption_write_failed');
                }
            });

            return $output;
        } catch (TranscodeQuotaExceeded) {
            throw new RuntimeException('caption_storage_quota_exceeded');
        } finally {
            $reservation->release();
            File::delete($publishTemporary);
            File::delete($temporaryOutput);
            File::delete($input);
            if ($source !== $input && $source !== $output) {
                File::delete($source);
            }
        }
    }

    private function extractArchiveEntry(
        ZipArchive $zip,
        int $index,
        string $path,
        int $expectedBytes,
        int $maximumBytes,
        TranscodeReservation $reservation,
    ): void {
        $input = @$zip->getStreamIndex($index);
        if (! is_resource($input)) {
            throw new RuntimeException('caption_archive_invalid');
        }

        $output = @fopen($path, 'xb');
        if ($output === false) {
            fclose($input);

            throw new RuntimeException('caption_write_failed');
        }

        $writtenBytes = 0;
        $emptyReads = 0;

        try {
            while (! feof($input)) {
                $chunk = fread($input, 64 * 1024);
                if ($chunk === false) {
                    throw new RuntimeException('caption_archive_invalid');
                }

                if ($chunk === '') {
                    if (! feof($input) && ++$emptyReads >= 3) {
                        throw new RuntimeException('caption_archive_invalid');
                    }

                    continue;
                }

                $emptyReads = 0;
                $length = strlen($chunk);
                $writtenBytes += $length;
                if (
                    $writtenBytes > $expectedBytes
                    || $writtenBytes > $maximumBytes
                ) {
                    throw new RuntimeException('caption_archive_invalid');
                }

                $offset = 0;
                while ($offset < $length) {
                    $written = fwrite($output, substr($chunk, $offset));
                    if ($written === false || $written === 0) {
                        throw new RuntimeException('caption_write_failed');
                    }
                    $offset += $written;
                }
                $reservation->consume($length);
            }

            if (
                $writtenBytes !== $expectedBytes
                || $writtenBytes < 1
                || ! fflush($output)
            ) {
                throw new RuntimeException('caption_archive_invalid');
            }
        } catch (Throwable $exception) {
            @unlink($path);

            throw $exception;
        } finally {
            fclose($output);
            fclose($input);
        }
    }

    private function consumeOutputGrowth(
        string $path,
        int &$observedBytes,
        int $maximumBytes,
        TranscodeReservation $reservation,
    ): void {
        clearstatcache(true, $path);
        $currentBytes = File::isFile($path)
            ? max(0, (int) File::size($path))
            : 0;

        if ($currentBytes < $observedBytes || $currentBytes > $maximumBytes) {
            throw new TranscodeQuotaExceeded;
        }

        if ($currentBytes > $observedBytes) {
            $reservation->consume($currentBytes - $observedBytes);
            $observedBytes = $currentBytes;
        }
    }

    private function looksLikeWebVtt(string $path): bool
    {
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            return false;
        }

        try {
            $prefix = fread($handle, 32);

            return is_string($prefix) && str_starts_with(ltrim($prefix), 'WEBVTT');
        } finally {
            fclose($handle);
        }
    }
}
