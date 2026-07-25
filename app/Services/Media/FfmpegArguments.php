<?php

namespace App\Services\Media;

class FfmpegArguments
{
    /**
     * @return list<string>
     */
    public function hls(string $sourcePath, string $manifestPath, string $segmentPattern, string $profile = 'auto', ?int $audioTrack = null): array
    {
        $arguments = [
            $this->binary(),
            '-hide_banner',
            '-loglevel',
            'error',
            '-nostdin',
            '-y',
            '-i',
            $sourcePath,
            '-map',
            '0:v:0?',
            '-map',
            '0:a:'.($audioTrack ?? 0).'?',
            '-c:v',
            'libx264',
            '-preset',
            'veryfast',
            '-profile:v',
            'main',
            '-pix_fmt',
            'yuv420p',
        ];
        if (in_array($profile, ['1080p', '720p'], true)) {
            $arguments = array_merge($arguments, ['-vf', $profile === '720p' ? 'scale=-2:720' : 'scale=-2:1080']);
        }

        return array_merge($arguments, [
            '-g',
            '100',
            '-keyint_min',
            '100',
            '-sc_threshold',
            '0',
            '-c:a',
            'aac',
            '-b:a',
            '128k',
            '-ac',
            '2',
            '-f',
            'hls',
            '-hls_time',
            '4',
            '-hls_playlist_type',
            'vod',
            '-hls_flags',
            'independent_segments',
            '-hls_segment_filename',
            $segmentPattern,
            $manifestPath,
        ]);
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
}
