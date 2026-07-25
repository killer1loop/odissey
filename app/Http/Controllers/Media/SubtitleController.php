<?php

namespace App\Http\Controllers\Media;

use App\Http\Controllers\Controller;
use App\Models\MediaItem;
use App\Services\Media\SourceMaterializer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\Process\Process;

class SubtitleController extends Controller
{
    public function __invoke(Request $request, SourceMaterializer $materializer, string $media, int $track): BinaryFileResponse
    {
        $item = MediaItem::query()->accessibleTo($request->user())->with('source')->findOrFail($media);
        $tracks = $item->metadata['technical']['subtitle_tracks'] ?? [];
        abort_unless(isset($tracks[$track]) && $track >= 0 && $track <= 31, 404);
        $directory = rtrim(config('odissey.transcode_path'), '/').'/subtitles/'.$request->user()->id.'/'.$item->id;
        $path = $directory.'/'.$track.'.vtt';
        if (! File::isFile($path)) {
            File::ensureDirectoryExists($directory, 0700);
            $source = $materializer->materialize($item);
            try {
                $process = new Process([
                    config('odissey.ffmpeg_binary', 'ffmpeg'), '-hide_banner', '-loglevel', 'error',
                    '-nostdin', '-y', '-i', $source['path'], '-map', '0:s:'.$track, '-f', 'webvtt', $path,
                ]);
                $process->setTimeout(120);
                $process->mustRun();
            } finally {
                if ($source['temporary']) {
                    File::delete($source['path']);
                }
            }
        }

        return response()->file($path, ['Content-Type' => 'text/vtt; charset=UTF-8', 'Cache-Control' => 'private, max-age=300', 'X-Content-Type-Options' => 'nosniff']);
    }
}
