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
        $fallback = $this->fallback($path);

        if ($localPath === null) {
            return $fallback;
        }

        return $this->inspectSource($localPath, $path) ?? $fallback;
    }

    /**
     * Probe a bounded input stream without exposing its remote locator to
     * FFprobe. The caller owns and must close the underlying stream.
     *
     * @return array<string, mixed>|null
     */
    public function inspectInput(mixed $input, string $path): ?array
    {
        return $this->inspectSource('pipe:0', $path, $input, 'pipe');
    }

    /** @return array<string, mixed>|null */
    private function inspectSource(
        string $source,
        string $path,
        mixed $input = null,
        string $protocolWhitelist = 'file,pipe',
    ): ?array {
        $fallback = $this->fallback($path);
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $kind = $fallback['media_kind'];
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
            '-show_entries', 'format=duration,format_name,bit_rate:stream=index,codec_type,codec_name,profile,level,width,height,channels,r_frame_rate,avg_frame_rate,bit_rate,pix_fmt,bits_per_raw_sample,bits_per_sample,color_transfer,color_primaries:stream_tags=language,title:format_tags=artist,album,title,track,date',
            '-of', 'json',
            '-protocol_whitelist', $protocolWhitelist,
            $source,
        ], 30);
        if ($input !== null) {
            $process->setInput($input);
        }
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
            return null;
        }
        $data = json_decode($output, true);
        if (! is_array($data)) {
            return null;
        }
        $video = collect($data['streams'] ?? [])->firstWhere('codec_type', 'video');
        $audio = collect($data['streams'] ?? [])->firstWhere('codec_type', 'audio');
        if (
            ($kind === 'video' && ! is_array($video))
            || ($kind === 'music' && ! is_array($audio))
        ) {
            return null;
        }
        $audioTracks = collect($data['streams'] ?? [])->where('codec_type', 'audio')->values()->map(fn ($stream, $index) => ['index' => $index, 'codec' => $stream['codec_name'] ?? null, 'language' => $stream['tags']['language'] ?? null, 'title' => $stream['tags']['title'] ?? null])->all();
        $subtitleTracks = collect($data['streams'] ?? [])->where('codec_type', 'subtitle')->values()->map(fn ($stream, $index) => ['index' => $index, 'codec' => $stream['codec_name'] ?? null, 'language' => $stream['tags']['language'] ?? null, 'title' => $stream['tags']['title'] ?? null])->all();
        $tags = array_change_key_case($data['format']['tags'] ?? [], CASE_LOWER);
        $videoCodec = $video['codec_name'] ?? null;
        $audioCodec = $audio['codec_name'] ?? null;
        if (
            ($kind === 'video' && ! is_string($videoCodec))
            || ($kind === 'music' && ! is_string($audioCodec))
        ) {
            return null;
        }
        $bitDepth = $this->bitDepth($video);
        $frameRate = $this->frameRate(
            $video['avg_frame_rate'] ?? $video['r_frame_rate'] ?? null,
        );
        $dynamicRange = $this->dynamicRange(
            $video['color_transfer'] ?? null,
            $bitDepth,
        );

        return array_merge($fallback, [
            'container' => explode(',', $data['format']['format_name'] ?? $extension)[0],
            'video_codec' => $videoCodec,
            'audio_codec' => $audioCodec,
            'duration_ms' => isset($data['format']['duration']) ? (int) round((float) $data['format']['duration'] * 1000) : null,
            'requires_transcode' => $kind === 'video' && ! ($videoCodec === 'h264' && in_array($audioCodec, ['aac', 'mp3'], true) && in_array($extension, ['mp4', 'm4v'], true)),
            'technical' => [
                'width' => $video['width'] ?? null,
                'height' => $video['height'] ?? null,
                'frame_rate' => $frameRate,
                'bit_rate' => $video['bit_rate']
                    ?? $data['format']['bit_rate']
                    ?? null,
                'video_profile' => $video['profile'] ?? null,
                'video_level' => $video['level'] ?? null,
                'bit_depth' => $bitDepth,
                'pixel_format' => $video['pix_fmt'] ?? null,
                'dynamic_range' => $dynamicRange,
                'color_primaries' => $video['color_primaries'] ?? null,
                'channels' => $audio['channels'] ?? null,
                'audio_channels' => $audio['channels'] ?? null,
                'audio_tracks' => $audioTracks,
                'subtitle_tracks' => $subtitleTracks,
            ],
            'tags' => $tags,
        ]);
    }

    /** @return array<string, mixed> */
    private function fallback(string $path): array
    {
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $kind = in_array(
            $extension,
            config('odissey.audio_extensions'),
            true,
        ) ? 'music' : 'video';

        return [
            'media_kind' => $kind,
            'mime_type' => match ($extension) {
                'mp4', 'm4v' => 'video/mp4', 'webm' => 'video/webm',
                'mp3' => 'audio/mpeg', 'm4a', 'aac' => 'audio/mp4',
                'flac' => 'audio/flac', 'ogg', 'opus' => 'audio/ogg',
                default => $kind === 'music'
                    ? 'application/octet-stream'
                    : 'video/x-matroska',
            },
            'container' => $extension,
            'requires_transcode' => ! in_array(
                $extension,
                [
                    'mp4',
                    'm4v',
                    'webm',
                    'mp3',
                    'm4a',
                    'aac',
                    'ogg',
                    'opus',
                ],
                true,
            ),
        ];
    }

    /**
     * @param  array<string, mixed>|null  $video
     */
    private function bitDepth(?array $video): ?int
    {
        foreach ([
            $video['bits_per_raw_sample'] ?? null,
            $video['bits_per_sample'] ?? null,
        ] as $value) {
            if (is_numeric($value) && (int) $value > 0) {
                return (int) $value;
            }
        }
        $pixelFormat = (string) ($video['pix_fmt'] ?? '');
        if (preg_match('/(?:p|gbrp)(\\d{2})(?:le|be)?$/', $pixelFormat, $match)) {
            return (int) $match[1];
        }
        if ($pixelFormat !== '') {
            return 8;
        }

        return null;
    }

    private function frameRate(mixed $value): ?float
    {
        if (is_numeric($value)) {
            return (float) $value > 0 ? round((float) $value, 3) : null;
        }
        if (
            ! is_string($value)
            || preg_match('/^(\\d+)\\/(\\d+)$/', $value, $match) !== 1
            || (int) $match[2] === 0
        ) {
            return null;
        }

        return round((int) $match[1] / (int) $match[2], 3);
    }

    private function dynamicRange(mixed $transfer, ?int $bitDepth): ?string
    {
        return match (strtolower((string) $transfer)) {
            'smpte2084' => 'hdr10',
            'arib-std-b67' => 'hlg',
            'bt709', 'iec61966-2-1' => 'sdr',
            default => $bitDepth !== null && $bitDepth <= 8 ? 'sdr' : null,
        };
    }
}
