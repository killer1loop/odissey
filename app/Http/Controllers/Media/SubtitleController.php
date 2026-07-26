<?php

namespace App\Http\Controllers\Media;

use App\Http\Controllers\Controller;
use App\Models\MediaItem;
use App\Services\Media\Exceptions\TranscodeQuotaExceeded;
use App\Services\Media\FfmpegArguments;
use App\Services\Media\FfmpegRunner;
use App\Services\Media\SourceMaterializer;
use App\Services\Media\TranscodeConcurrencyGate;
use App\Services\Media\TranscodeStorage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

class SubtitleController extends Controller
{
    public function __invoke(
        Request $request,
        SourceMaterializer $materializer,
        FfmpegRunner $runner,
        FfmpegArguments $arguments,
        TranscodeConcurrencyGate $concurrency,
        TranscodeStorage $storage,
        string $media,
        int $track,
    ): BinaryFileResponse {
        $item = MediaItem::query()->accessibleTo($request->user())->with('source')->findOrFail($media);
        $tracks = $item->metadata['technical']['subtitle_tracks'] ?? [];
        abort_unless(isset($tracks[$track]) && $track >= 0 && $track <= 31, 404);
        $directory = $storage->transientDirectory('subtitles', create: true)
            .'/'.$request->user()->id.'/'.$item->id;
        $path = $directory.'/'.$track.'.vtt';
        if (! File::isFile($path)) {
            File::ensureDirectoryExists($directory, 0700);
            $lock = Cache::lock(
                'odissey:media:subtitle:'.$request->user()->id.':'.$item->id.':'.$track,
                max(30, (int) config('odissey.embedded_subtitle_timeout_seconds', 120) + 30),
            );
            abort_unless($lock->get(), 409, 'Subtitle extraction is already in progress.');

            try {
                if (! File::isFile($path)) {
                    $lease = $concurrency->acquire(
                        max(30, (int) config('odissey.embedded_subtitle_timeout_seconds', 120) + 30),
                    );
                    abort_if($lease === null, 429, 'Media processing is currently at capacity.');

                    $source = null;
                    $temporaryOutput = $path.'.'.Str::lower((string) Str::ulid()).'.tmp';

                    try {
                        $source = $materializer->materialize($item);
                        $maximumBytes = min(
                            64 * 1024 * 1024,
                            max(1, (int) config('odissey.embedded_subtitle_max_bytes', 20 * 1024 * 1024)),
                        );
                        $runner->run(
                            $arguments->subtitle($source['path'], $track, $temporaryOutput),
                            max(10, (int) config('odissey.embedded_subtitle_timeout_seconds', 120)),
                            static fn (): bool => (
                                (! File::exists($temporaryOutput) || File::size($temporaryOutput) <= $maximumBytes)
                                && $storage->isWithinStorageLimits()
                            ),
                        );

                        if (
                            ! File::isFile($temporaryOutput)
                            || File::size($temporaryOutput) < 1
                            || File::size($temporaryOutput) > $maximumBytes
                        ) {
                            throw new TranscodeQuotaExceeded;
                        }

                        if (! File::move($temporaryOutput, $path)) {
                            throw new \RuntimeException('subtitle_publish_failed');
                        }
                    } catch (TranscodeQuotaExceeded) {
                        abort(507, 'Subtitle extraction exceeded the configured storage limit.');
                    } finally {
                        File::delete($temporaryOutput);
                        if (($source['temporary'] ?? false) === true) {
                            File::delete($source['path']);
                        }

                        try {
                            $lease->release();
                        } catch (Throwable) {
                            // The short lease expires automatically if the cache backend fails.
                        }
                    }
                }
            } finally {
                try {
                    $lock->release();
                } catch (Throwable) {
                    // The short lock expires automatically if the cache backend fails.
                }
            }
        }

        return response()->file($path, ['Content-Type' => 'text/vtt; charset=UTF-8', 'Cache-Control' => 'private, max-age=300', 'X-Content-Type-Options' => 'nosniff']);
    }
}
