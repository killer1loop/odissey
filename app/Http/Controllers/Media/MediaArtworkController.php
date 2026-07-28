<?php

namespace App\Http\Controllers\Media;

use App\Http\Controllers\Controller;
use App\Models\MediaItem;
use App\Services\Media\ArtworkManager;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

class MediaArtworkController extends Controller
{
    public function __invoke(Request $request, ArtworkManager $artwork, string $media, string $kind): BinaryFileResponse
    {
        abort_unless(in_array($kind, ['poster', 'backdrop'], true), 404);
        $dimensions = $request->validate([
            'width' => [
                'nullable',
                'integer',
                'min:'.ArtworkManager::MIN_VARIANT_DIMENSION,
                'max:'.ArtworkManager::MAX_VARIANT_DIMENSION,
            ],
            'height' => [
                'nullable',
                'integer',
                'min:'.ArtworkManager::MIN_VARIANT_DIMENSION,
                'max:'.ArtworkManager::MAX_VARIANT_DIMENSION,
            ],
        ]);
        $width = isset($dimensions['width'])
            ? (int) $dimensions['width']
            : null;
        $height = isset($dimensions['height'])
            ? (int) $dimensions['height']
            : null;
        if (
            $width !== null
            && $height !== null
            && $width * $height > ArtworkManager::MAX_VARIANT_PIXELS
        ) {
            throw ValidationException::withMessages([
                'width' => [
                    'The requested artwork dimensions exceed the pixel limit.',
                ],
            ]);
        }
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
        if ($width !== null || $height !== null) {
            try {
                $path = $artwork->variantPath(
                    $item,
                    $kind,
                    $width,
                    $height,
                ) ?? $path;
            } catch (Throwable) {
                // Resizing is an optimization. The cached original remains
                // available when the bounded image process or quota is busy.
            }
        }

        $response = response()->file($path, [
            'Content-Type' => 'image/jpeg',
            'Cross-Origin-Resource-Policy' => 'same-origin',
            'X-Content-Type-Options' => 'nosniff',
        ]);
        $response->setPrivate();
        $response->setMaxAge(86400);
        $etag = hash_file('sha256', $path);
        if (is_string($etag) && $etag !== '') {
            $response->setEtag($etag);
        }
        $modifiedAt = filemtime($path);
        if (is_int($modifiedAt)) {
            $response->setLastModified(
                (new \DateTimeImmutable)->setTimestamp($modifiedAt),
            );
        }
        $response->isNotModified($request);

        return $response;
    }
}
