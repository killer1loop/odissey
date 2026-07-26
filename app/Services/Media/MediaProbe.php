<?php

namespace App\Services\Media;

use Symfony\Component\Process\Process;

class MediaProbe
{
    public function __construct(
        private readonly MediaProcessFactory $processes,
    ) {}

    /** @return array<string, mixed> */
    public function inspect(?string $localPath, string $path): array
    {
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $kind = in_array($extension, config('odissey.audio_extensions'), true) ? 'music' : 'video';
        $fallback = [
            'media_kind' => $kind,
            'mime_type' => match ($extension) {
                'mp4', 'm4v' => 'video/mp4', 'webm' => 'video/webm',
                'mp3' => 'audio/mpeg', 'm4a', 'aac' => 'audio/mp4',
                'flac' => 'audio/flac', 'ogg', 'opus' => 'audio/ogg',
                default => $kind === 'music' ? 'application/octet-stream' : 'video/x-matroska',
            },
            'container' => $extension,
            'requires_transcode' => ! in_array($extension, ['mp4', 'm4v', 'webm', 'mp3', 'm4a', 'aac', 'ogg', 'opus'], true),
        ];
        if ($localPath === null) {
            return $fallback;
        }

        $process = $this->processes->make([
            config('odissey.ffprobe_binary', 'ffprobe'), '-v', 'error',
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
            '-probesize', '10485760',
            '-analyzeduration', '10000000',
            '-show_entries', 'format=duration,format_name:stream=index,codec_type,codec_name,width,height,channels:stream_tags=language,title:format_tags=artist,album,title,track,date',
            '-of', 'json',
            '-protocol_whitelist', 'file,pipe',
            $localPath,
        ], 30);
        $maximumOutputBytes = min(
            16 * 1024 * 1024,
            max(
                1024,
                (int) config(
                    'odissey.ffprobe_max_output_bytes',
                    4 * 1024 * 1024,
                ),
            ),
        );
        $output = '';
        $outputExceeded = false;
        $process->run(
            static function (string $type, string $chunk) use (
                &$output,
                &$outputExceeded,
                $maximumOutputBytes,
            ): void {
                if ($type !== Process::OUT || $outputExceeded) {
                    return;
                }

                $remaining = $maximumOutputBytes - strlen($output);
                if (strlen($chunk) > $remaining) {
                    if ($remaining > 0) {
                        $output .= substr($chunk, 0, $remaining);
                    }
                    $outputExceeded = true;

                    return;
                }

                $output .= $chunk;
            },
        );
        if (! $process->isSuccessful() || $outputExceeded) {
            return $fallback;
        }
        $data = json_decode($output, true);
        $video = collect($data['streams'] ?? [])->firstWhere('codec_type', 'video');
        $audio = collect($data['streams'] ?? [])->firstWhere('codec_type', 'audio');
        $audioTracks = collect($data['streams'] ?? [])->where('codec_type', 'audio')->values()->map(fn ($stream, $index) => ['index' => $index, 'codec' => $stream['codec_name'] ?? null, 'language' => $stream['tags']['language'] ?? null, 'title' => $stream['tags']['title'] ?? null])->all();
        $subtitleTracks = collect($data['streams'] ?? [])->where('codec_type', 'subtitle')->values()->map(fn ($stream, $index) => ['index' => $index, 'codec' => $stream['codec_name'] ?? null, 'language' => $stream['tags']['language'] ?? null, 'title' => $stream['tags']['title'] ?? null])->all();
        $tags = array_change_key_case($data['format']['tags'] ?? [], CASE_LOWER);
        $videoCodec = $video['codec_name'] ?? null;
        $audioCodec = $audio['codec_name'] ?? null;

        return array_merge($fallback, [
            'container' => explode(',', $data['format']['format_name'] ?? $extension)[0],
            'video_codec' => $videoCodec,
            'audio_codec' => $audioCodec,
            'duration_ms' => isset($data['format']['duration']) ? (int) round((float) $data['format']['duration'] * 1000) : null,
            'requires_transcode' => $kind === 'video' && ! ($videoCodec === 'h264' && in_array($audioCodec, ['aac', 'mp3'], true) && in_array($extension, ['mp4', 'm4v'], true)),
            'technical' => ['width' => $video['width'] ?? null, 'height' => $video['height'] ?? null, 'channels' => $audio['channels'] ?? null, 'audio_tracks' => $audioTracks, 'subtitle_tracks' => $subtitleTracks],
            'tags' => $tags,
        ]);
    }
}
