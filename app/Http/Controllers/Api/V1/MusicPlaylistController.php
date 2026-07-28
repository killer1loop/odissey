<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\MediaItemResource;
use App\Models\MediaItem;
use App\Models\MusicPlaylist;
use App\Models\MusicPlaylistItem;
use App\Models\User;
use App\Services\Api\MusicPlaylistMutationLock;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\CursorPaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class MusicPlaylistController extends Controller
{
    public function __construct(
        private readonly MusicPlaylistMutationLock $mutations,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
            'cursor' => ['nullable', 'string', 'max:512'],
        ]);
        $playlists = MusicPlaylist::query()
            ->whereBelongsTo($request->user())
            ->withCount('items')
            ->orderBy('normalized_name')
            ->orderBy('id')
            ->cursorPaginate($filters['limit'] ?? 50);

        return response()->json([
            'data' => $playlists->getCollection()
                ->map(fn (MusicPlaylist $playlist): array => (
                    $this->summary($playlist)
                )),
            'page' => $this->page($playlists),
        ], headers: [
            'Cache-Control' => 'private, max-age=30',
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validatedWrite($request);
        $playlist = $this->mutations->synchronized(
            $request->user(),
            fn (): MusicPlaylist => DB::transaction(function () use (
                $request,
                $data,
            ): MusicPlaylist {
                User::query()
                    ->whereKey($request->user()->getKey())
                    ->lockForUpdate()
                    ->firstOrFail();
                abort_if(
                    MusicPlaylist::query()
                        ->whereBelongsTo($request->user())
                        ->count()
                    >= $this->maximumPlaylists(),
                    409,
                    'The maximum number of music playlists has been reached.',
                );
                $this->assertNameAvailable(
                    $request->user(),
                    $data['name'],
                );

                $trackIds = $this->validatedTrackIds(
                    $request,
                    $data['trackIds'],
                );
                $playlist = MusicPlaylist::query()->create([
                    'user_id' => $request->user()->getKey(),
                    'name' => $data['name'],
                    'normalized_name' => Str::lower($data['name']),
                ]);
                $this->replaceItems($playlist, $trackIds);

                return $playlist;
            }, 5),
        );

        return response()->json([
            'data' => $this->detail($request, $playlist, false),
        ], 201, [
            'Cache-Control' => 'no-store',
        ]);
    }

    public function show(Request $request, string $playlist): JsonResponse
    {
        return response()->json([
            'data' => $this->detail(
                $request,
                $this->owned($request, $playlist),
            ),
        ], headers: [
            'Cache-Control' => 'private, max-age=30',
        ]);
    }

    public function update(
        Request $request,
        string $playlist,
    ): JsonResponse {
        $data = $this->validatedWrite($request);
        $updated = $this->mutations->synchronized(
            $request->user(),
            fn (): MusicPlaylist => DB::transaction(function () use (
                $request,
                $playlist,
                $data,
            ): MusicPlaylist {
                User::query()
                    ->whereKey($request->user()->getKey())
                    ->lockForUpdate()
                    ->firstOrFail();
                $owned = MusicPlaylist::query()
                    ->whereBelongsTo($request->user())
                    ->lockForUpdate()
                    ->findOrFail($playlist);
                $this->assertNameAvailable(
                    $request->user(),
                    $data['name'],
                    $owned,
                );
                $trackIds = $this->validatedTrackIds(
                    $request,
                    $data['trackIds'],
                );
                $owned->fill([
                    'name' => $data['name'],
                    'normalized_name' => Str::lower($data['name']),
                ]);
                if ($owned->isDirty()) {
                    $owned->save();
                }
                $this->replaceItems($owned, $trackIds);

                return $owned;
            }, 5),
        );

        return response()->json([
            'data' => $this->detail($request, $updated, false),
        ], headers: [
            'Cache-Control' => 'no-store',
        ]);
    }

    public function destroy(
        Request $request,
        string $playlist,
    ): JsonResponse {
        $request->validate([
            'confirmation' => [
                'required',
                'string',
                'in:delete-playlist',
            ],
        ]);
        $this->mutations->synchronized(
            $request->user(),
            fn (): mixed => DB::transaction(function () use (
                $request,
                $playlist,
            ): mixed {
                User::query()
                    ->whereKey($request->user()->getKey())
                    ->lockForUpdate()
                    ->firstOrFail();
                $owned = MusicPlaylist::query()
                    ->whereBelongsTo($request->user())
                    ->lockForUpdate()
                    ->findOrFail($playlist);

                return $owned->delete();
            }, 5),
        );

        return response()->json([
            'deleted' => true,
        ], headers: [
            'Cache-Control' => 'no-store',
        ]);
    }

    /**
     * @return array{name: string, trackIds: list<string>}
     */
    private function validatedWrite(Request $request): array
    {
        if (is_string($request->input('name'))) {
            $request->merge([
                'name' => Str::squish((string) $request->input('name')),
            ]);
        }
        /** @var array{name: string, trackIds: list<string>} $validated */
        $validated = $request->validate([
            'name' => ['required', 'string', 'min:1', 'max:100'],
            'trackIds' => [
                'present',
                'array',
                'max:'.$this->maximumTracks(),
            ],
            'trackIds.*' => [
                'required',
                'string',
                'ulid',
                'distinct',
            ],
        ]);

        return $validated;
    }

    /**
     * @param  list<string>  $trackIds
     * @return list<string>
     */
    private function validatedTrackIds(
        Request $request,
        array $trackIds,
    ): array {
        if ($trackIds === []) {
            return [];
        }

        $validIds = MediaItem::query()
            ->accessibleTo($request->user())
            ->where('media_kind', 'music')
            ->whereIn('id', $trackIds)
            ->pluck('id')
            ->map(static fn (mixed $id): string => (string) $id)
            ->all();
        if (count($validIds) !== count($trackIds)) {
            throw ValidationException::withMessages([
                'trackIds' => [
                    'Every track must identify an accessible music item.',
                ],
            ]);
        }

        return array_values($trackIds);
    }

    /**
     * @param  list<string>  $trackIds
     */
    private function replaceItems(
        MusicPlaylist $playlist,
        array $trackIds,
    ): void {
        $currentTrackIds = $playlist->items()
            ->pluck('media_item_id')
            ->map(static fn (mixed $id): string => (string) $id)
            ->values()
            ->all();
        if ($currentTrackIds === $trackIds) {
            return;
        }

        $playlist->items()->delete();
        if ($trackIds === []) {
            return;
        }

        $now = now();
        $rows = array_map(
            static fn (string $trackId, int $position): array => [
                'id' => (string) Str::ulid(),
                'music_playlist_id' => $playlist->getKey(),
                'media_item_id' => $trackId,
                'position' => $position,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            $trackIds,
            array_keys($trackIds),
        );
        foreach (array_chunk($rows, 250) as $chunk) {
            MusicPlaylistItem::query()->insert($chunk);
        }
    }

    private function owned(
        Request $request,
        string $playlist,
    ): MusicPlaylist {
        return MusicPlaylist::query()
            ->whereBelongsTo($request->user())
            ->findOrFail($playlist);
    }

    /**
     * @return array<string, mixed>
     */
    private function detail(
        Request $request,
        MusicPlaylist $playlist,
        bool $useRequestPagination = true,
    ): array {
        $filters = $useRequestPagination
            ? $request->validate([
                'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
                'cursor' => ['nullable', 'string', 'max:512'],
            ])
            : [];
        $playlist->loadCount('items');
        $items = MusicPlaylistItem::query()
            ->whereBelongsTo($playlist, 'playlist')
            ->with([
                'mediaItem.source',
                'mediaItem.progress' => fn ($query) => $query
                    ->whereBelongsTo($request->user()),
                'mediaItem.favorites' => fn ($query) => $query->where(
                    'user_id',
                    $request->user()->getKey(),
                ),
            ])
            ->orderBy('position')
            ->orderBy('id')
            ->cursorPaginate(
                $filters['limit'] ?? 50,
                ['*'],
                'cursor',
                $useRequestPagination ? null : '',
            );

        return [
            ...$this->summary($playlist),
            'items' => $items->getCollection()
                ->map(fn (MusicPlaylistItem $item): array => [
                    'position' => $item->position,
                    'track' => (new MediaItemResource(
                        $item->mediaItem,
                    ))->resolve($request),
                ])
                ->values(),
            'itemsPage' => $this->page($items),
        ];
    }

    private function assertNameAvailable(
        User $user,
        string $name,
        ?MusicPlaylist $ignored = null,
    ): void {
        $exists = MusicPlaylist::query()
            ->whereBelongsTo($user)
            ->where('normalized_name', Str::lower($name))
            ->when(
                $ignored !== null,
                fn ($query) => $query->where(
                    'id',
                    '!=',
                    $ignored->getKey(),
                ),
            )
            ->exists();
        if ($exists) {
            throw ValidationException::withMessages([
                'name' => [
                    'A playlist with this name already exists.',
                ],
            ]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function summary(MusicPlaylist $playlist): array
    {
        return [
            'id' => (string) $playlist->getKey(),
            'name' => $playlist->name,
            'trackCount' => (int) $playlist->items_count,
            'createdAt' => $playlist->created_at->utc()->toIso8601String(),
            'updatedAt' => $playlist->updated_at->utc()->toIso8601String(),
        ];
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

    private function maximumPlaylists(): int
    {
        return min(
            1000,
            max(
                10,
                (int) config(
                    'native-client.maximum_music_playlists_per_user',
                    250,
                ),
            ),
        );
    }

    private function maximumTracks(): int
    {
        return min(
            1000,
            max(
                100,
                (int) config(
                    'native-client.maximum_music_playlist_tracks',
                    1000,
                ),
            ),
        );
    }
}
