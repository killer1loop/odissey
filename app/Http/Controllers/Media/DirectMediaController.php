<?php

namespace App\Http\Controllers\Media;

use App\Http\Controllers\Controller;
use App\Models\MediaItem;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class DirectMediaController extends Controller
{
    public function __invoke(Request $request, string $media): BinaryFileResponse
    {
        $item = MediaItem::query()
            ->whereBelongsTo($request->user())
            ->findOrFail($media);

        abort_if($item->requires_transcode, 409);

        $sourcePath = $item->source_locator;
        abort_unless(File::isFile($sourcePath) && File::isReadable($sourcePath), 404);

        return response()->file($sourcePath, [
            'Accept-Ranges' => 'bytes',
            'Cache-Control' => 'private, no-store',
            'Content-Type' => $item->mime_type ?: 'application/octet-stream',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
