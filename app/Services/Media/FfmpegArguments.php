<?php

namespace App\Services\Media;

class FfmpegArguments
{
    /**
     * @return list<string>
     */
    public function hls(
        string $sourcePath,
        string $manifestPath,
        string $segmentPattern,
        string $profile = 'auto',
        ?int $audioTrack = null,
        string $deliveryMode = 'fullTranscode',
    ): array {
        if (! in_array(
            $deliveryMode,
            ['remux', 'audioTranscode', 'fullTranscode'],
            true,
        )) {
            $deliveryMode = 'fullTranscode';
        }
        $protocolWhitelist = preg_match(
            '#\Ahttp://127\.0\.0\.1:8000/_internal/media/transcodes/[0-9A-HJKMNP-TV-Z]{26}/source\?#i',
            $sourcePath,
        ) === 1
            ? 'file,pipe,http,tcp'
            : 'file,pipe';
        $arguments = [
            $this->binary(),
            '-hide_banner',
            '-loglevel',
            'error',
            '-nostdin',
            '-y',
            '-max_alloc',
            (string) $this->maximumAllocationBytes(),
            '-max_pixels',
            (string) $this->maximumPixels(),
            '-threads',
            (string) $this->threads(),
            '-protocol_whitelist',
            $protocolWhitelist,
            '-i',
            $sourcePath,
            '-map',
            '0:v:0?',
            '-map',
            '0:a:'.($audioTrack ?? 0).'?',
        ];
        if ($deliveryMode === 'fullTranscode') {
            $arguments = array_merge($arguments, [
                '-c:v',
                'libx264',
                '-threads',
                (string) $this->threads(),
                '-filter_threads',
                (string) $this->threads(),
                '-preset',
                'veryfast',
                '-profile:v',
                'main',
                '-pix_fmt',
                'yuv420p',
            ]);
            if (in_array($profile, ['1080p', '720p'], true)) {
                $arguments = array_merge($arguments, [
                    '-vf',
                    $profile === '720p'
                        ? 'scale=-2:720'
                        : 'scale=-2:1080',
                ]);
            }
            $arguments = array_merge($arguments, [
                '-g',
                '100',
                '-keyint_min',
                '100',
                '-sc_threshold',
                '0',
                '-c:a',
                'aac',
                '-maxrate:v',
                $this->maximumVideoBitrate().'k',
                '-bufsize:v',
                ($this->maximumVideoBitrate() * 2).'k',
                '-b:a',
                '128k',
                '-ac',
                '2',
            ]);
        } elseif ($deliveryMode === 'audioTranscode') {
            $arguments = array_merge($arguments, [
                '-c:v',
                'copy',
                '-c:a',
                'aac',
                '-b:a',
                '384k',
            ]);
        } else {
            $arguments = array_merge($arguments, [
                '-c:v',
                'copy',
                '-c:a',
                'copy',
            ]);
        }

        $hlsArguments = [
            '-f',
            'hls',
            '-hls_time',
            '4',
            '-hls_playlist_type',
            'event',
            '-hls_flags',
            'independent_segments',
        ];
        if ($deliveryMode !== 'fullTranscode') {
            $hlsArguments = array_merge($hlsArguments, [
                '-hls_segment_type',
                'fmp4',
                '-hls_fmp4_init_filename',
                'init.mp4',
            ]);
        }

        return array_merge($arguments, $hlsArguments, [
            '-hls_segment_filename',
            $segmentPattern,
            $manifestPath,
        ]);
    }

    /**
     * @return list<string>
     */
    public function subtitle(string $sourcePath, int $track, string $outputPath): array
    {
        return [
            $this->binary(),
            '-hide_banner',
            '-loglevel',
            'error',
            '-nostdin',
            '-y',
            '-max_alloc',
            (string) $this->maximumAllocationBytes(),
            '-max_pixels',
            (string) $this->maximumPixels(),
            '-threads',
            (string) $this->threads(),
            '-protocol_whitelist',
            'file,pipe',
            '-i',
            $sourcePath,
            '-map',
            '0:s:'.$track,
            '-f',
            'webvtt',
            $outputPath,
        ];
    }

    /**
     * @return list<string>
     */
    public function directPlayFixture(string $outputPath, int $durationSeconds): array
    {
        return [
            $this->binary(),
            '-hide_banner',
            '-loglevel',
            'error',
            '-nostdin',
            '-y',
            '-f',
            'lavfi',
            '-i',
            'testsrc2=size=640x360:rate=25',
            '-f',
            'lavfi',
            '-i',
            'sine=frequency=880:sample_rate=48000',
            '-t',
            (string) $durationSeconds,
            '-shortest',
            '-c:v',
            'libx264',
            '-preset',
            'veryfast',
            '-pix_fmt',
            'yuv420p',
            '-c:a',
            'aac',
            '-b:a',
            '128k',
            '-movflags',
            '+faststart',
            $outputPath,
        ];
    }

    /**
     * @return list<string>
     */
    public function incompatibleFixture(string $outputPath, int $durationSeconds): array
    {
        return [
            $this->binary(),
            '-hide_banner',
            '-loglevel',
            'error',
            '-nostdin',
            '-y',
            '-f',
            'lavfi',
            '-i',
            'testsrc2=size=640x360:rate=25',
            '-f',
            'lavfi',
            '-i',
            'sine=frequency=440:sample_rate=48000',
            '-t',
            (string) $durationSeconds,
            '-shortest',
            '-c:v',
            'ffv1',
            '-level',
            '3',
            '-c:a',
            'pcm_s16le',
            $outputPath,
        ];
    }

    private function binary(): string
    {
        return (string) config('odissey.ffmpeg_binary', 'ffmpeg');
    }

    private function threads(): int
    {
        return min(16, max(1, (int) config('odissey.ffmpeg_threads', 2)));
    }

    private function maximumAllocationBytes(): int
    {
        return min(
            1024 * 1024 * 1024,
            max(
                16 * 1024 * 1024,
                (int) config('odissey.ffmpeg_max_alloc_bytes', 256 * 1024 * 1024),
            ),
        );
    }

    private function maximumPixels(): int
    {
        return min(
            7680 * 4320,
            max(1920 * 1080, (int) config('odissey.ffmpeg_max_pixels', 7680 * 4320)),
        );
    }

    private function maximumVideoBitrate(): int
    {
        return min(
            50000,
            max(1000, (int) config('odissey.ffmpeg_max_video_bitrate_kbps', 10000)),
        );
    }
}
