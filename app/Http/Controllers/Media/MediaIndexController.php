<?php

namespace App\Http\Controllers\Media;

use App\Http\Controllers\Controller;
use App\Models\MediaItem;
use App\Models\MediaSource;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

class MediaIndexController extends Controller
{
    public function __invoke(Request $request): View
    {
        $filters = $request->validate([
            'favorites' => ['nullable', 'boolean'],
            'kind' => ['nullable', 'in:video,music'],
            'library' => ['nullable', 'in:movies,tv'],
            'q' => ['nullable', 'string', 'max:200'],
            'series' => ['nullable', 'string', 'max:255'],
            'source' => ['nullable', 'string', 'max:26'],
        ]);
        $kind = $filters['kind'] ?? 'video';
        $library = $kind === 'video'
            ? ($filters['library'] ?? 'movies')
            : null;
        $query = $filters['q'] ?? null;
        $series = $filters['series'] ?? null;
        $sourceId = $filters['source'] ?? null;
        $base = MediaItem::query()
            ->accessibleTo($request->user())
            ->where('media_kind', $kind)
            ->when(
                $sourceId !== null,
                fn ($builder) => $builder->where(
                    'media_source_id',
                    $sourceId,
                ),
            )
            ->when(
                $query !== null,
                fn ($builder) => $builder->where(
                    'title',
                    'like',
                    '%'.addcslashes($query, '%_\\').'%',
                ),
            )
            ->when(
                $request->boolean('favorites'),
                fn ($builder) => $builder->whereHas(
                    'favorites',
                    fn ($favoriteQuery) => $favoriteQuery->where(
                        'user_id',
                        $request->user()->id,
                    ),
                ),
            );

        if ($kind === 'video' && $library === 'movies') {
            $base->where(function ($builder): void {
                $builder->where('metadata->kind', 'movie')
                    ->orWhereNull('metadata');
            });
        }
        if ($kind === 'video' && $library === 'tv') {
            $base->whereIn('metadata->kind', ['series', 'episode']);
        }
        if ($series !== null) {
            $base->where('metadata->kind', 'episode')
                ->where('metadata->series_title', $series);
        }

        $seriesGroups = collect();
        if ($kind === 'video' && $library === 'tv' && $series === null) {
            $seriesGroups = (clone $base)
                ->with('source')
                ->orderBy('title')
                ->limit(min(
                    10000,
                    max(
                        100,
                        (int) config('odissey.library_series_group_limit', 5000),
                    ),
                ))
                ->get()
                ->groupBy(fn (MediaItem $item): string => (string) (
                    $item->metadata['series_title'] ?? $item->title
                ));
        }

        $items = (clone $base)
            ->when(
                $kind === 'video' && $library === 'tv' && $series === null,
                fn ($builder) => $builder->whereRaw('1 = 0'),
            )
            ->with([
                'source',
                'progress' => fn ($query) => $query->whereBelongsTo($request->user()),
                'favorites' => fn ($query) => $query->where('user_id', $request->user()->id),
            ])
            ->orderBy('title')
            ->paginate(60)
            ->withQueryString();

        $sources = MediaSource::query()
            ->where('enabled', true)
            ->whereHas('items', function ($builder) use ($kind, $library): void {
                $builder->where('media_kind', $kind)
                    ->whereNull('missing_at');
                if ($kind === 'video' && $library === 'movies') {
                    $builder->where('metadata->kind', 'movie');
                }
                if ($kind === 'video' && $library === 'tv') {
                    $builder->whereIn(
                        'metadata->kind',
                        ['series', 'episode'],
                    );
                }
            })
            ->orderBy('name')
            ->get(['id', 'name', 'type']);

        return view('media.index', compact(
            'items',
            'kind',
            'library',
            'series',
            'seriesGroups',
            'sources',
        ));
    }
}
