<?php

namespace App\Services\Media;

use App\Models\MediaItem;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Process\Process;

class ArtworkManager
{
    public function populate(MediaItem $item, ?string $localPath): void
    {
        $directory = rtrim(config('odissey.artwork_path'), '/').'/'.$item->getKey();
        File::ensureDirectoryExists($directory, 0700);
        $metadata = $item->metadata ?? [];
        foreach (['poster', 'backdrop'] as $kind) {
            $url = $metadata[$kind.'_url'] ?? null;
            if ($url) {
                $host = strtolower((string) parse_url($url, PHP_URL_HOST));
                if (! in_array($host, ['image.tmdb.org', 'static.tvmaze.com'], true)) {
                    continue;
                }
                $response = Http::timeout(15)->maxRedirects(2)->get($url);
                if ($response->successful() && strlen($response->body()) <= config('odissey.artwork_max_bytes') && str_starts_with($response->header('Content-Type', ''), 'image/')) {
                    File::put($directory.'/'.$kind.'.jpg', $response->body());
                    $metadata[$kind.'_cached'] = true;
                }
            }
        }
        if (empty($metadata['poster_cached']) && $localPath && $item->media_kind === 'video') {
            $process = new Process([
                config('odissey.ffmpeg_binary', 'ffmpeg'), '-hide_banner', '-loglevel', 'error',
                '-nostdin', '-y', '-ss', '00:00:05', '-i', $localPath,
                '-frames:v', '1', '-vf', 'scale=480:-2', $directory.'/poster.jpg',
            ]);
            $process->setTimeout(30);
            $process->run();
            $metadata['poster_cached'] = $process->isSuccessful();
        }
        $item->update(['metadata' => $metadata]);
    }

    public function path(MediaItem $item, string $kind): ?string
    {
        $path = rtrim(config('odissey.artwork_path'), '/').'/'.$item->getKey().'/'.$kind.'.jpg';

        return File::isFile($path) ? $path : null;
    }
}
