<?php

namespace App\Http\Controllers\Media;

use App\Http\Controllers\Controller;
use App\Models\MediaItem;
use App\Models\MediaSubtitle;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ExternalSubtitleController extends Controller
{
    public function __invoke(Request $request, string $media, string $subtitle): BinaryFileResponse
    {
        $item = MediaItem::query()->accessibleTo($request->user())->findOrFail($media);
        $caption = MediaSubtitle::query()->whereBelongsTo($item, 'mediaItem')->findOrFail($subtitle);
        abort_unless(File::isFile($caption->path), 404);

        return response()->file($caption->path, ['Content-Type' => 'text/vtt; charset=UTF-8', 'Cache-Control' => 'private, max-age=300', 'X-Content-Type-Options' => 'nosniff']);
    }
}
