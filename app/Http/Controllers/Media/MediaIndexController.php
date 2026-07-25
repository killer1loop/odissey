<?php

namespace App\Http\Controllers\Media;

use App\Http\Controllers\Controller;
use App\Models\MediaItem;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

class MediaIndexController extends Controller
{
    public function __invoke(Request $request): View
    {
        $items = MediaItem::query()
            ->whereBelongsTo($request->user())
            ->with(['progress' => fn ($query) => $query->whereBelongsTo($request->user())])
            ->latest()
            ->get();

        return view('media.index', compact('items'));
    }
}
