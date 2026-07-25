<?php

namespace App\Http\Controllers\Media;

use App\Http\Controllers\Controller;
use App\Models\MediaItem;
use App\Models\TranscodeSession;
use App\Services\Media\TranscodeStorage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class HlsSegmentController extends Controller
{
    public function __invoke(
        Request $request,
        TranscodeStorage $storage,
        string $media,
        string $session,
        string $segment,
    ): BinaryFileResponse {
        $item = MediaItem::query()
            ->accessibleTo($request->user())
            ->findOrFail($media);
        $session = TranscodeSession::query()
            ->whereBelongsTo($request->user())
            ->whereBelongsTo($item, 'mediaItem')
            ->findOrFail($session);

        abort_unless($session->isAvailable(), 404);

        try {
            $path = $storage->segmentPath($session, $segment);
        } catch (RuntimeException) {
            abort(404);
        }

        abort_unless(File::isFile($path), 404);

        return response()->file($path, [
            'Cache-Control' => 'private, max-age=300',
            'Content-Type' => 'video/mp2t',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
