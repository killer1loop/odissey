<?php

namespace App\Services\Media;

use Symfony\Component\Process\Process;

class MediaProbe
{
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

        $process = new Process([
            config('odissey.ffprobe_binary', 'ffprobe'), '-v', 'error',
            '-show_entries', 'format=duration,format_name:stream=index,codec_type,codec_name,width,height,channels:stream_tags=language,title:format_tags=artist,album,title,track,date',
            '-of', 'json', $localPath,
        ]);
        $process->setTimeout(30);
        $process->run();
        if (! $process->isSuccessful()) {
            return $fallback;
        }
        $data = json_decode($process->getOutput(), true);
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
