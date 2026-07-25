<?php

namespace App\Http\Controllers\Media;

use App\Http\Controllers\Controller;
use App\Models\MediaItem;
use App\Models\TranscodeSession;
use App\Services\Media\TranscodeStorage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Symfony\Component\HttpFoundation\Response;

class HlsManifestController extends Controller
{
    public function __invoke(
        Request $request,
        TranscodeStorage $storage,
        string $media,
        string $session,
    ): Response {
        $item = MediaItem::query()
            ->whereBelongsTo($request->user())
            ->findOrFail($media);
        $session = TranscodeSession::query()
            ->whereBelongsTo($request->user())
            ->whereBelongsTo($item, 'mediaItem')
            ->findOrFail($session);

        abort_unless($session->isAvailable(), 404);

        $path = $storage->manifestPath($session);
        abort_unless(File::isFile($path), 404);

        return response(File::get($path), 200, [
            'Cache-Control' => 'private, no-store',
            'Content-Type' => 'application/vnd.apple.mpegurl',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
