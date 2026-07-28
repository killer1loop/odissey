<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\MediaItemResource;
use App\Models\MediaFavorite;
use App\Models\MediaItem;
use App\Models\MediaSource;
use App\Models\PlaybackHistory;
use App\Models\PlaybackProgress;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\CursorPaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class CatalogController extends Controller
{
    public function home(Request $request): JsonResponse
    {
        $user = $request->user();
        $continueWatching = $this->baseItems($request)
            ->whereHas('progress', fn (Builder $query) => $query
                ->where('user_id', $user->id)
                ->where('completed', false)
                ->where('position_ms', '>', 0))
            ->with([
                'progress' => fn ($query) => $query->whereBelongsTo($user),
            ])
            ->orderByDesc(
                PlaybackProgress::query()
                    ->select('updated_at')
                    ->whereColumn(
                        'playback_progress.media_item_id',
                        'media_items.id',
                    )
                    ->where('user_id', $user->id)
                    ->limit(1),
            )
            ->limit(20)
            ->get();
        $recentlyAdded = $this->baseItems($request)
            ->latest('created_at')
            ->limit(20)
            ->get();
        $favorites = $this->baseItems($request)
            ->whereHas(
                'favorites',
                fn ($query) => $query->where('user_id', $user->id),
            )
            ->withMax([
                'favorites as favorite_added_at' => fn ($query) => $query
                    ->where('user_id', $user->id),
            ], 'created_at')
            ->orderByDesc('favorite_added_at')
            ->limit(20)
            ->get();

        return response()->json([
            'sections' => [
                [
                    'id' => 'continue-watching',
                    'title' => 'Continue Watching',
                    'items' => MediaItemResource::collection($continueWatching),
                ],
                [
                    'id' => 'recently-added',
                    'title' => 'Recently Added',
                    'items' => MediaItemResource::collection($recentlyAdded),
                ],
                [
                    'id' => 'favorites',
                    'title' => 'Favorites',
                    'items' => MediaItemResource::collection($favorites),
                ],
            ],
        ], headers: ['Cache-Control' => 'private, max-age=15']);
    }

    public function libraries(Request $request): JsonResponse
    {
        $counts = MediaItem::query()
            ->accessibleTo($request->user())
            ->selectRaw(
                "CASE
                    WHEN media_kind = 'music' THEN 'music'
                    WHEN json_extract(metadata, '$.kind') = 'series' THEN 'tv'
                    WHEN json_extract(metadata, '$.kind') = 'episode' THEN 'episodes'
                    ELSE 'movies'
                END AS library_id",
            )
            ->selectRaw('COUNT(*) AS aggregate')
            ->groupBy('library_id')
            ->pluck('aggregate', 'library_id');
        $sources = MediaSource::query()
            ->where('enabled', true)
            ->orderBy('name')
            ->get(['id', 'name', 'type'])
            ->map(fn (MediaSource $source): array => [
                'id' => (string) $source->getKey(),
                'name' => $source->name,
                'type' => $source->type,
            ]);

        return response()->json([
            'data' => [
                [
                    'id' => 'movies',
                    'name' => 'Movies',
                    'kind' => 'movies',
                    'itemCount' => (int) ($counts['movies'] ?? 0),
                ],
                [
                    'id' => 'tv',
                    'name' => 'TV Shows',
                    'kind' => 'tv',
                    'itemCount' => (int) ($counts['tv'] ?? 0),
                ],
                [
                    'id' => 'music',
                    'name' => 'Music',
                    'kind' => 'music',
                    'itemCount' => (int) ($counts['music'] ?? 0),
                ],
            ],
            'sources' => $sources,
        ], headers: ['Cache-Control' => 'private, max-age=60']);
    }

    public function libraryItems(
        Request $request,
        string $library,
    ): JsonResponse {
        abort_unless(in_array($library, ['movies', 'tv', 'music'], true), 404);
        $this->normalizeBooleanQuery($request, 'favorite');
        $filters = $request->validate([
            'sourceId' => ['nullable', 'string', 'max:26'],
            'favorite' => ['nullable', 'boolean'],
            'sort' => [
                'nullable',
                Rule::in(['recently_added', 'title', 'release_date']),
            ],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
            'cursor' => ['nullable', 'string', 'max:512'],
        ]);
        $query = $this->baseItems($request)
            ->when(
                isset($filters['sourceId']),
                fn (Builder $builder) => $builder->where(
                    'media_source_id',
                    $filters['sourceId'],
                ),
            )
            ->when(
                ($filters['favorite'] ?? false) === true,
                fn (Builder $builder) => $builder->whereHas(
                    'favorites',
                    fn (Builder $favorite) => $favorite->where(
                        'user_id',
                        $request->user()->id,
                    ),
                ),
            );
        $this->applyLibrary($query, $library);

        $this->applyItemSort($query, $filters['sort'] ?? 'title');
        $items = $query->cursorPaginate($filters['limit'] ?? 50);

        return $this->paginated($items);
    }

    public function media(Request $request, string $media): JsonResponse
    {
        $item = $this->baseItems($request)
            ->with('subtitles')
            ->findOrFail($media);

        return response()->json([
            'data' => new MediaItemResource($item),
        ], headers: ['Cache-Control' => 'private, max-age=30']);
    }

    public function search(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'q' => ['required', 'string', 'min:1', 'max:200'],
            'kind' => ['nullable', Rule::in(['movies', 'tv', 'music'])],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
            'cursor' => ['nullable', 'string', 'max:512'],
        ]);
        $escaped = addcslashes(trim($filters['q']), '\\%_');
        $query = $this->baseItems($request)
            ->whereRaw(
                "title LIKE ? ESCAPE '\\'",
                ['%'.$escaped.'%'],
            );
        if (isset($filters['kind'])) {
            $this->applyLibrary($query, $filters['kind']);
        }
        $items = $query
            ->orderBy('title')
            ->orderBy('id')
            ->cursorPaginate($filters['limit'] ?? 50);

        return $this->paginated($items);
    }

    public function favorites(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
            'cursor' => ['nullable', 'string', 'max:512'],
        ]);
        $items = $this->baseItems($request)
            ->whereHas(
                'favorites',
                fn ($query) => $query->where(
                    'user_id',
                    $request->user()->id,
                ),
            )
            ->orderBy('title')
            ->orderBy('id')
            ->cursorPaginate($filters['limit'] ?? 50);

        return $this->paginated($items);
    }

    public function storeFavorite(
        Request $request,
        string $kind,
        string $id,
    ): JsonResponse {
        abort_unless($kind === 'media', 404);
        $item = MediaItem::query()
            ->accessibleTo($request->user())
            ->findOrFail($id);
        MediaFavorite::query()->firstOrCreate([
            'user_id' => $request->user()->id,
            'media_item_id' => $item->id,
        ]);

        return response()->json(['favorite' => true], headers: [
            'Cache-Control' => 'no-store',
        ]);
    }

    public function destroyFavorite(
        Request $request,
        string $kind,
        string $id,
    ): JsonResponse {
        abort_unless($kind === 'media', 404);
        MediaItem::query()
            ->accessibleTo($request->user())
            ->findOrFail($id);
        MediaFavorite::query()->where([
            'user_id' => $request->user()->id,
            'media_item_id' => $id,
        ])->delete();

        return response()->json(['favorite' => false], headers: [
            'Cache-Control' => 'no-store',
        ]);
    }

    public function history(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
            'cursor' => ['nullable', 'string', 'max:512'],
        ]);
        $progress = PlaybackProgress::query()
            ->whereBelongsTo($request->user())
            ->whereHas(
                'mediaItem',
                fn ($query) => $query->accessibleTo($request->user()),
            )
            ->with([
                'mediaItem.source',
                'mediaItem.progress' => fn ($query) => $query->whereBelongsTo(
                    $request->user(),
                ),
                'mediaItem.favorites' => fn ($query) => $query->where(
                    'user_id',
                    $request->user()->id,
                ),
            ])
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->cursorPaginate($filters['limit'] ?? 50);
        $items = $progress->getCollection()
            ->map(fn (PlaybackProgress $entry) => $entry->mediaItem);

        return response()->json([
            'data' => MediaItemResource::collection($items),
            'page' => $this->page($progress),
        ], headers: ['Cache-Control' => 'private, max-age=15']);
    }

    public function progress(
        Request $request,
        string $media,
    ): JsonResponse {
        $validated = $request->validate([
            'sequence' => ['required', 'integer', 'min:1', 'max:2147483647'],
            'positionMs' => [
                'required',
                'integer',
                'min:0',
                'max:604800000',
            ],
            'durationMs' => [
                'nullable',
                'integer',
                'min:1',
                'max:604800000',
            ],
            'completed' => ['sometimes', 'boolean'],
        ]);
        $item = MediaItem::query()
            ->accessibleTo($request->user())
            ->findOrFail($media);

        [$entry, $accepted] = DB::transaction(function () use (
            $request,
            $item,
            $validated,
        ): array {
            $entry = PlaybackProgress::query()
                ->whereBelongsTo($request->user())
                ->whereBelongsTo($item, 'mediaItem')
                ->lockForUpdate()
                ->first();

            if (
                $entry !== null
                && $validated['sequence'] <= $entry->sequence
            ) {
                return [$entry, false];
            }

            $duration = $validated['durationMs'] ?? $item->duration_ms;
            $position = $duration === null
                ? $validated['positionMs']
                : min($validated['positionMs'], $duration);
            $previousPosition = (int) ($entry?->position_ms ?? 0);
            $wasCompleted = (bool) ($entry?->completed ?? false);
            $completed = (bool) ($validated['completed'] ?? false);

            $entry ??= new PlaybackProgress([
                'user_id' => $request->user()->id,
                'media_item_id' => $item->id,
            ]);
            $entry->fill([
                'position_ms' => $position,
                'duration_ms' => $duration,
                'sequence' => $validated['sequence'],
                'completed' => $completed,
            ])->save();
            $watched = min(max(0, $position - $previousPosition), 120000);

            if ($watched > 0) {
                $aggregationSeconds = min(
                    300,
                    max(
                        10,
                        (int) config(
                            'odissey.playback_history_aggregation_seconds',
                            60,
                        ),
                    ),
                );
                $history = PlaybackHistory::query()
                    ->whereBelongsTo($request->user())
                    ->whereBelongsTo($item, 'mediaItem')
                    ->where('event', 'progress')
                    ->where(
                        'played_at',
                        '>=',
                        now()->subSeconds($aggregationSeconds),
                    )
                    ->lockForUpdate()
                    ->latest('played_at')
                    ->first();
                if ($history === null) {
                    PlaybackHistory::query()->create([
                        'user_id' => $request->user()->id,
                        'media_item_id' => $item->id,
                        'event' => 'progress',
                        'position_ms' => $position,
                        'watched_ms' => $watched,
                        'played_at' => now(),
                    ]);
                } else {
                    $history->forceFill([
                        'position_ms' => $position,
                        'watched_ms' => min(
                            $aggregationSeconds * 1000,
                            max(0, (int) $history->watched_ms) + $watched,
                        ),
                        'played_at' => now(),
                    ])->save();
                }
            }
            if ($completed && ! $wasCompleted) {
                PlaybackHistory::query()->create([
                    'user_id' => $request->user()->id,
                    'media_item_id' => $item->id,
                    'event' => 'completed',
                    'position_ms' => $position,
                    'watched_ms' => 0,
                    'played_at' => now(),
                ]);
            }

            return [$entry, true];
        }, 3);

        return response()->json([
            'accepted' => $accepted,
            'sequence' => (int) $entry->sequence,
            'positionMs' => (int) $entry->position_ms,
            'completed' => (bool) $entry->completed,
        ], headers: ['Cache-Control' => 'no-store']);
    }

    public function watched(
        Request $request,
        string $media,
    ): JsonResponse {
        $item = MediaItem::query()
            ->accessibleTo($request->user())
            ->findOrFail($media);
        $current = PlaybackProgress::query()
            ->whereBelongsTo($request->user())
            ->whereBelongsTo($item, 'mediaItem')
            ->first();
        $request->merge([
            'sequence' => ((int) ($current?->sequence ?? 0)) + 1,
            'positionMs' => (int) (
                $current?->duration_ms ?? $item->duration_ms ?? 0
            ),
            'durationMs' => $current?->duration_ms ?? $item->duration_ms,
            'completed' => true,
        ]);

        return $this->progress($request, $media);
    }

    public function tracks(Request $request, string $media): JsonResponse
    {
        $item = MediaItem::query()
            ->accessibleTo($request->user())
            ->findOrFail($media);
        $technical = is_array($item->metadata['technical'] ?? null)
            ? $item->metadata['technical']
            : [];

        return response()->json([
            'audio' => $this->safeTracks($technical['audio_tracks'] ?? []),
            'subtitles' => $this->safeTracks(
                $technical['subtitle_tracks'] ?? [],
            ),
        ], headers: ['Cache-Control' => 'private, max-age=60']);
    }

    public function captions(Request $request, string $media): JsonResponse
    {
        $item = MediaItem::query()
            ->accessibleTo($request->user())
            ->with('subtitles')
            ->findOrFail($media);

        return response()->json([
            'data' => $item->subtitles->map(
                fn ($subtitle): array => [
                    'id' => (string) $subtitle->getKey(),
                    'codec' => 'webvtt',
                    'language' => $subtitle->language,
                    'label' => $subtitle->label,
                    'hearingImpaired' => (bool) $subtitle->hearing_impaired,
                    'format' => 'webvtt',
                    'url' => route('api.v1.media.captions.show', [
                        $item->getKey(),
                        $subtitle->getKey(),
                    ]),
                ],
            ),
        ], headers: ['Cache-Control' => 'private, max-age=60']);
    }

    public function seasons(Request $request, string $series): JsonResponse
    {
        $parent = MediaItem::query()
            ->accessibleTo($request->user())
            ->where('metadata->kind', 'series')
            ->findOrFail($series);
        $title = (string) (
            $parent->metadata['series_title'] ?? $parent->title
        );
        $seasons = MediaItem::query()
            ->accessibleTo($request->user())
            ->where('media_source_id', $parent->media_source_id)
            ->where('metadata->kind', 'episode')
            ->where('metadata->series_title', $title)
            ->selectRaw(
                "CAST(COALESCE(json_extract(metadata, '$.season_number'), 0) AS INTEGER) AS season_number",
            )
            ->selectRaw('COUNT(*) AS episode_count')
            ->groupBy('season_number')
            ->orderBy('season_number')
            ->get()
            ->map(fn (MediaItem $season): array => [
                'id' => $parent->getKey().':'.(int) $season->season_number,
                'seriesId' => (string) $parent->getKey(),
                'number' => (int) $season->season_number,
                'episodeCount' => (int) $season->episode_count,
            ]);

        return response()->json(['data' => $seasons], headers: [
            'Cache-Control' => 'private, max-age=60',
        ]);
    }

    public function episodes(Request $request, string $season): JsonResponse
    {
        $filters = $request->validate([
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
            'cursor' => ['nullable', 'string', 'max:512'],
        ]);
        if (
            preg_match(
                '/^([0-9A-HJKMNP-TV-Z]{26}):(\d{1,4})$/i',
                $season,
                $matches,
            ) !== 1
        ) {
            abort(404);
        }
        $parent = MediaItem::query()
            ->accessibleTo($request->user())
            ->where('metadata->kind', 'series')
            ->findOrFail($matches[1]);
        $title = (string) (
            $parent->metadata['series_title'] ?? $parent->title
        );
        $items = $this->baseItems($request)
            ->where('media_source_id', $parent->media_source_id)
            ->where('metadata->kind', 'episode')
            ->where('metadata->series_title', $title)
            ->where('metadata->season_number', (int) $matches[2])
            ->select('media_items.*')
            ->selectRaw(
                "CAST(COALESCE(json_extract(metadata, '$.episode_number'), 0) AS INTEGER) AS episode_sort",
            )
            ->orderBy('episode_sort')
            ->orderBy('id')
            ->cursorPaginate($filters['limit'] ?? 50);

        return $this->paginated($items);
    }

    private function baseItems(Request $request): Builder
    {
        return MediaItem::query()
            ->accessibleTo($request->user())
            ->with([
                'source:id,name,type',
                'progress' => fn ($query) => $query->whereBelongsTo(
                    $request->user(),
                ),
                'favorites' => fn ($query) => $query->where(
                    'user_id',
                    $request->user()->id,
                ),
            ]);
    }

    public function musicArtists(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
            'cursor' => ['nullable', 'string', 'max:512'],
        ]);
        $artists = MediaItem::query()
            ->accessibleTo($request->user())
            ->where('media_kind', 'music')
            ->selectRaw(
                "COALESCE(NULLIF(json_extract(metadata, '$.artist'), ''), 'Unknown Artist') AS artist_name",
            )
            ->selectRaw('COUNT(*) AS track_count')
            ->selectRaw('MIN(id) AS representative_id')
            ->groupBy('artist_name')
            ->orderBy('artist_name')
            ->cursorPaginate($filters['limit'] ?? 50);
        $page = $this->page($artists);
        $artists->setCollection(
            $artists->getCollection()->map(fn (MediaItem $artist): array => [
                'id' => hash('sha256', (string) $artist->artist_name),
                'name' => (string) $artist->artist_name,
                'trackCount' => (int) $artist->track_count,
                'artworkUrl' => route(
                    'api.v1.media.artwork',
                    [$artist->representative_id, 'poster'],
                ),
            ]),
        );

        return response()->json([
            'data' => $artists->getCollection(),
            'page' => $page,
        ], headers: [
            'Cache-Control' => 'private, max-age=60',
        ]);
    }

    public function musicAlbums(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'artist' => ['nullable', 'string', 'max:255'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
            'cursor' => ['nullable', 'string', 'max:512'],
        ]);
        $albums = MediaItem::query()
            ->accessibleTo($request->user())
            ->where('media_kind', 'music')
            ->when(
                isset($validated['artist']),
                fn (Builder $query) => $query->whereRaw(
                    "COALESCE(NULLIF(json_extract(metadata, '$.artist'), ''), 'Unknown Artist') = ?",
                    [$validated['artist']],
                ),
            )
            ->selectRaw(
                "COALESCE(NULLIF(json_extract(metadata, '$.album'), ''), 'Unknown Album') AS album_name",
            )
            ->selectRaw(
                "COALESCE(NULLIF(json_extract(metadata, '$.artist'), ''), 'Unknown Artist') AS artist_name",
            )
            ->selectRaw('COUNT(*) AS track_count')
            ->selectRaw('MIN(id) AS representative_id')
            ->selectRaw(
                "MAX(CAST(json_extract(metadata, '$.year') AS INTEGER)) AS release_year",
            )
            ->groupBy('album_name', 'artist_name')
            ->orderBy('artist_name')
            ->orderBy('album_name')
            ->cursorPaginate($validated['limit'] ?? 50);
        $page = $this->page($albums);
        $albums->setCollection(
            $albums->getCollection()->map(fn (MediaItem $album): array => [
                'id' => hash(
                    'sha256',
                    $album->artist_name."\0".$album->album_name,
                ),
                'name' => (string) $album->album_name,
                'artist' => (string) $album->artist_name,
                'trackCount' => (int) $album->track_count,
                'artworkUrl' => route(
                    'api.v1.media.artwork',
                    [$album->representative_id, 'poster'],
                ),
                'year' => is_numeric($album->release_year)
                    ? (int) $album->release_year
                    : null,
            ]),
        );

        return response()->json([
            'data' => $albums->getCollection(),
            'page' => $page,
        ], headers: [
            'Cache-Control' => 'private, max-age=60',
        ]);
    }

    public function musicTracks(Request $request): JsonResponse
    {
        $this->normalizeBooleanQuery($request, 'favorite');
        $filters = $request->validate([
            'artist' => ['nullable', 'string', 'max:255'],
            'album' => ['nullable', 'string', 'max:255'],
            'sourceId' => ['nullable', 'string', 'max:26'],
            'favorite' => ['nullable', 'boolean'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
            'cursor' => ['nullable', 'string', 'max:512'],
        ]);
        $query = $this->baseItems($request)
            ->where('media_kind', 'music')
            ->when(
                isset($filters['artist']),
                fn (Builder $builder) => $builder->whereRaw(
                    "COALESCE(NULLIF(json_extract(metadata, '$.artist'), ''), 'Unknown Artist') = ?",
                    [$filters['artist']],
                ),
            )
            ->when(
                isset($filters['album']),
                fn (Builder $builder) => $builder->whereRaw(
                    "COALESCE(NULLIF(json_extract(metadata, '$.album'), ''), 'Unknown Album') = ?",
                    [$filters['album']],
                ),
            )
            ->when(
                isset($filters['sourceId']),
                fn (Builder $builder) => $builder->where(
                    'media_source_id',
                    $filters['sourceId'],
                ),
            )
            ->when(
                ($filters['favorite'] ?? false) === true,
                fn (Builder $builder) => $builder->whereHas(
                    'favorites',
                    fn (Builder $favorite) => $favorite->where(
                        'user_id',
                        $request->user()->id,
                    ),
                ),
            );
        if (isset($filters['artist'], $filters['album'])) {
            $query->select('media_items.*')
                ->selectRaw(
                    "CAST(COALESCE(json_extract(metadata, '$.disc_number'), json_extract(metadata, '$.disc'), 0) AS INTEGER) AS disc_sort",
                )
                ->selectRaw(
                    "CAST(COALESCE(json_extract(metadata, '$.track_number'), json_extract(metadata, '$.track'), 0) AS INTEGER) AS track_sort",
                )
                ->orderBy('disc_sort')
                ->orderBy('track_sort');
        }
        $items = $query
            ->orderBy('title')
            ->orderBy('id')
            ->cursorPaginate($filters['limit'] ?? 50);

        return $this->paginated($items);
    }

    private function applyLibrary(Builder $query, string $library): void
    {
        match ($library) {
            'music' => $query->where('media_kind', 'music'),
            'tv' => $query
                ->where('media_kind', 'video')
                ->where('metadata->kind', 'series'),
            default => $query
                ->where('media_kind', 'video')
                ->where(function (Builder $builder): void {
                    $builder->where('metadata->kind', 'movie')
                        ->orWhereNull('metadata');
                }),
        };
    }

    private function applyItemSort(Builder $query, string $sort): void
    {
        if ($sort === 'recently_added') {
            $query->orderByDesc('created_at')->orderByDesc('id');

            return;
        }
        if ($sort === 'release_date') {
            $query->select('media_items.*')
                ->selectRaw(
                    "COALESCE(NULLIF(json_extract(metadata, '$.release_date'), ''), printf('%04d-01-01', CAST(COALESCE(json_extract(metadata, '$.year'), 0) AS INTEGER)), '') AS release_date_sort",
                )
                ->orderByDesc('release_date_sort')
                ->orderBy('title')
                ->orderBy('id');

            return;
        }

        $query->orderBy('title')->orderBy('id');
    }

    private function paginated(CursorPaginator $items): JsonResponse
    {
        return response()->json([
            'data' => MediaItemResource::collection($items->getCollection()),
            'page' => $this->page($items),
        ], headers: ['Cache-Control' => 'private, max-age=30']);
    }

    /**
     * @return array<string, mixed>
     */
    private function page(CursorPaginator $paginator): array
    {
        return [
            'perPage' => $paginator->perPage(),
            'nextCursor' => $paginator->nextCursor()?->encode(),
            'previousCursor' => $paginator->previousCursor()?->encode(),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function safeTracks(mixed $tracks): array
    {
        if (! is_array($tracks)) {
            return [];
        }

        return collect($tracks)
            ->take(64)
            ->values()
            ->map(fn (mixed $track, int $index): array => [
                'id' => (string) $index,
                'codec' => is_array($track)
                    ? mb_substr((string) ($track['codec'] ?? ''), 0, 32)
                    : '',
                'language' => is_array($track)
                    ? mb_substr((string) ($track['language'] ?? ''), 0, 16)
                    : '',
                'label' => is_array($track)
                    ? mb_substr((string) ($track['title'] ?? ''), 0, 255)
                    : '',
                'channels' => is_array($track)
                    && is_numeric($track['channels'] ?? null)
                    ? (int) $track['channels']
                    : null,
            ])
            ->all();
    }

    private function normalizeBooleanQuery(
        Request $request,
        string $key,
    ): void {
        $value = $request->query($key);
        if (! is_string($value)) {
            return;
        }

        $normalized = match (strtolower($value)) {
            '1', 'true' => true,
            '0', 'false' => false,
            default => null,
        };
        if ($normalized !== null) {
            $request->merge([$key => $normalized]);
        }
    }
}
