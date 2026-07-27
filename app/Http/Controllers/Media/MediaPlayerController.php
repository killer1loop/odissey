<?php

namespace App\Http\Controllers\Media;

use App\Http\Controllers\Controller;
use App\Models\MediaItem;
use App\Models\PlaybackHistory;
use App\Models\TranscodeSession;
use App\Services\Media\TranscodeStorage;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class MediaPlayerController extends Controller
{
    public function __invoke(
        Request $request,
        TranscodeStorage $storage,
        string $media,
    ): View|RedirectResponse {
        $item = MediaItem::query()
            ->accessibleTo($request->user())
            ->with('subtitles')
            ->findOrFail($media);

        if (($item->metadata['kind'] ?? null) === 'series') {
            return redirect()->route('media.index', [
                'kind' => 'video',
                'library' => 'tv',
                'series' => $item->metadata['series_title'] ?? $item->title,
                'source' => $item->media_source_id,
            ]);
        }

        $progress = $item->progress()
            ->whereBelongsTo($request->user())
            ->first();

        $session = $item->transcodeSessions()
            ->whereBelongsTo($request->user())
            ->where(function ($query): void {
                $query
                    ->whereNot('status', TranscodeSession::STATUS_READY)
                    ->orWhereNull('expires_at')
                    ->orWhere('expires_at', '>', now());
            })
            ->latest()
            ->first();

        if ($session?->isAvailable() && ! $storage->hasCompleteOutput($session)) {
            $storage->delete($session);
            $session->delete();
            $session = null;
        }

        $lastStart = $item->playbackHistory()
            ->whereBelongsTo($request->user())
            ->where('event', 'started')
            ->latest('played_at')
            ->first();

        if ($lastStart === null || $lastStart->played_at->lt(now()->subMinutes(5))) {
            PlaybackHistory::query()->create([
                'user_id' => $request->user()->getKey(),
                'media_item_id' => $item->getKey(),
                'event' => 'started',
                'position_ms' => $progress?->position_ms ?? 0,
                'played_at' => now(),
            ]);
        }

        $recentHistory = $this->recentHistory($request, $item);

        return view('media.show', compact('item', 'progress', 'session', 'recentHistory'));
    }

    /**
     * @return Collection<int, array{
     *     item: MediaItem,
     *     position_ms: int,
     *     duration_ms: int|null,
     *     progress_percent: int,
     *     remaining_ms: int|null,
     *     is_current: bool
     * }>
     */
    private function recentHistory(Request $request, MediaItem $currentItem): Collection
    {
        $mediaIds = PlaybackHistory::query()
            ->whereBelongsTo($request->user())
            ->latest('played_at')
            ->limit(100)
            ->pluck('media_item_id')
            ->unique()
            ->take(30)
            ->values();

        $items = MediaItem::query()
            ->accessibleTo($request->user())
            ->whereIn('id', $mediaIds)
            ->with([
                'progress' => fn ($query) => $query->whereBelongsTo($request->user()),
            ])
            ->get()
            ->keyBy('id');

        return $mediaIds
            ->map(function (string $mediaId) use ($items, $currentItem): ?array {
                /** @var MediaItem|null $historyItem */
                $historyItem = $items->get($mediaId);

                if ($historyItem === null || $historyItem->media_kind !== 'video') {
                    return null;
                }

                $positionMs = max(0, (int) ($historyItem->progress?->position_ms ?? 0));
                $durationMs = $historyItem->progress?->duration_ms
                    ?? $historyItem->duration_ms;
                $durationMs = $durationMs === null ? null : max(0, (int) $durationMs);
                $progressPercent = $durationMs > 0
                    ? min(100, (int) round(($positionMs / $durationMs) * 100))
                    : 0;

                return [
                    'item' => $historyItem,
                    'position_ms' => $positionMs,
                    'duration_ms' => $durationMs,
                    'progress_percent' => $progressPercent,
                    'remaining_ms' => $durationMs === null
                        ? null
                        : max(0, $durationMs - $positionMs),
                    'is_current' => $historyItem->is($currentItem),
                ];
            })
            ->filter()
            ->values();
    }
}
