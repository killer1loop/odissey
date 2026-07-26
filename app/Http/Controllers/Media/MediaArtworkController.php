<?php

namespace App\Http\Controllers\Media;

use App\Http\Controllers\Controller;
use App\Models\MediaItem;
use App\Services\Media\ArtworkManager;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class MediaArtworkController extends Controller
{
    public function __invoke(Request $request, ArtworkManager $artwork, string $media, string $kind): BinaryFileResponse
    {
        abort_unless(in_array($kind, ['poster', 'backdrop'], true), 404);
        $item = MediaItem::accessibleTo($request->user())->findOrFail($media);
        $path = $artwork->path($item, $kind);
        abort_if($path === null, 404);

        return response()->file($path, [
            'Cache-Control' => 'private, max-age=86400',
            'Content-Type' => 'image/jpeg',
            'Cross-Origin-Resource-Policy' => 'same-origin',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
