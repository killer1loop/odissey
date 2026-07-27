<?php

namespace App\Http\Controllers\Media;

use App\Http\Controllers\Controller;
use App\Models\MediaItem;
use App\Services\Media\ArtworkManager;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

class MediaArtworkController extends Controller
{
    public function __invoke(Request $request, ArtworkManager $artwork, string $media, string $kind): BinaryFileResponse
    {
        abort_unless(in_array($kind, ['poster', 'backdrop'], true), 404);
        $item = MediaItem::accessibleTo($request->user())->findOrFail($media);
        $path = $artwork->path($item, $kind);
        if (
            $path === null
            && is_string($item->metadata[$kind.'_url'] ?? null)
        ) {
            try {
                $artwork->populate($item, null);
            } catch (Throwable) {
                // Remote artwork is optional. A failed bounded download
                // remains a normal 404 and never breaks the library page.
            }
            $path = $artwork->path($item->refresh(), $kind);
        }
        abort_if($path === null, 404);

        return response()->file($path, [
            'Cache-Control' => 'private, max-age=86400',
            'Content-Type' => 'image/jpeg',
            'Cross-Origin-Resource-Policy' => 'same-origin',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
