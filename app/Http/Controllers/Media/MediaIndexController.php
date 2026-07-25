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
        $kind = $request->string('kind')->toString() ?: 'video';
        $items = MediaItem::query()
            ->accessibleTo($request->user())
            ->where('media_kind', $kind === 'music' ? 'music' : 'video')
            ->when($request->filled('series'), fn ($query) => $query->where('metadata->series_title', $request->string('series')->toString()))
            ->when($request->filled('q'), fn ($query) => $query->where('title', 'like', '%'.addcslashes($request->string('q')->toString(), '%_\\').'%'))
            ->when($request->boolean('favorites'), fn ($query) => $query->whereHas('favorites', fn ($q) => $q->where('user_id', $request->user()->id)))
            ->with([
                'source',
                'progress' => fn ($query) => $query->whereBelongsTo($request->user()),
                'favorites' => fn ($query) => $query->where('user_id', $request->user()->id),
            ])
            ->orderBy('title')
            ->get();

        return view('media.index', compact('items', 'kind'));
    }
}
