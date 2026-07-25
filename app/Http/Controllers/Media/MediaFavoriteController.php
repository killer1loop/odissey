<?php

namespace App\Http\Controllers\Media;

use App\Http\Controllers\Controller;
use App\Models\MediaFavorite;
use App\Models\MediaItem;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class MediaFavoriteController extends Controller
{
    public function store(Request $request, string $media): RedirectResponse
    {
        $item = MediaItem::accessibleTo($request->user())->findOrFail($media);
        MediaFavorite::firstOrCreate(['user_id' => $request->user()->id, 'media_item_id' => $item->id]);

        return back();
    }

    public function destroy(Request $request, string $media): RedirectResponse
    {
        MediaFavorite::where(['user_id' => $request->user()->id, 'media_item_id' => $media])->delete();

        return back();
    }
}
