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
        $filters = $request->validate([
            'favorites' => ['nullable', 'boolean'],
            'kind' => ['nullable', 'in:video,music'],
            'q' => ['nullable', 'string', 'max:200'],
            'series' => ['nullable', 'string', 'max:255'],
        ]);
        $kind = $filters['kind'] ?? 'video';
        $query = $filters['q'] ?? null;
        $series = $filters['series'] ?? null;
        $items = MediaItem::query()
            ->accessibleTo($request->user())
            ->where('media_kind', $kind)
            ->when($series !== null, fn ($builder) => $builder->where('metadata->series_title', $series))
            ->when($query !== null, fn ($builder) => $builder->where('title', 'like', '%'.addcslashes($query, '%_\\').'%'))
            ->when($request->boolean('favorites'), fn ($query) => $query->whereHas('favorites', fn ($q) => $q->where('user_id', $request->user()->id)))
            ->with([
                'source',
                'progress' => fn ($query) => $query->whereBelongsTo($request->user()),
                'favorites' => fn ($query) => $query->where('user_id', $request->user()->id),
            ])
            ->orderBy('title')
            ->paginate(60)
            ->withQueryString();

        return view('media.index', compact('items', 'kind'));
    }
}
