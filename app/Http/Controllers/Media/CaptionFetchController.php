<?php

namespace App\Http\Controllers\Media;

use App\Http\Controllers\Controller;
use App\Jobs\Media\FetchMediaCaptions;
use App\Models\MediaItem;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class CaptionFetchController extends Controller
{
    public function __invoke(Request $request, string $media): RedirectResponse
    {
        $item = MediaItem::query()->accessibleTo($request->user())->findOrFail($media);
        abort_unless($item->media_kind === 'video', 409);
        FetchMediaCaptions::dispatch($item->id);

        return back()->with('status', 'Caption search queued.');
    }
}
