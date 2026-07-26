<?php

namespace App\Http\Controllers\Media;

use App\Http\Controllers\Controller;
use App\Http\Requests\Media\PlaybackHeartbeatRequest;
use App\Models\MediaItem;
use App\Models\PlaybackHistory;
use App\Models\PlaybackProgress;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class PlaybackProgressController extends Controller
{
    public function __invoke(PlaybackHeartbeatRequest $request, string $media): JsonResponse
    {
        $item = MediaItem::query()
            ->accessibleTo($request->user())
            ->findOrFail($media);
        $validated = $request->validated();

        [$progress, $accepted] = DB::transaction(function () use ($request, $item, $validated): array {
            $progress = PlaybackProgress::query()
                ->whereBelongsTo($request->user())
                ->whereBelongsTo($item, 'mediaItem')
                ->lockForUpdate()
                ->first();

            if ($progress !== null && $validated['sequence'] <= $progress->sequence) {
                return [$progress, false];
            }

            $durationMs = $validated['duration_ms'] ?? $item->duration_ms;
            $positionMs = $durationMs === null
                ? $validated['position_ms']
                : min($validated['position_ms'], $durationMs);
            $completed = (bool) ($validated['completed'] ?? false);
            $wasCompleted = $progress?->completed ?? false;
            $previousPosition = (int) ($progress?->position_ms ?? 0);

            if ($progress === null) {
                $progress = new PlaybackProgress([
                    'user_id' => $request->user()->getKey(),
                    'media_item_id' => $item->getKey(),
                ]);
            }

            $progress->fill([
                'position_ms' => $positionMs,
                'duration_ms' => $durationMs,
                'sequence' => $validated['sequence'],
                'completed' => $completed,
            ])->save();

            $watchedMs = min(
                max(0, $positionMs - $previousPosition),
                120000,
            );

            if ($watchedMs > 0) {
                $aggregationSeconds = min(
                    300,
                    max(10, (int) config('odissey.playback_history_aggregation_seconds', 60)),
                );
                $history = PlaybackHistory::query()
                    ->whereBelongsTo($request->user())
                    ->whereBelongsTo($item, 'mediaItem')
                    ->where('event', 'progress')
                    ->where('played_at', '>=', now()->subSeconds($aggregationSeconds))
                    ->lockForUpdate()
                    ->latest('played_at')
                    ->first();

                if ($history === null) {
                    PlaybackHistory::query()->create([
                        'user_id' => $request->user()->getKey(),
                        'media_item_id' => $item->getKey(),
                        'event' => 'progress',
                        'position_ms' => $positionMs,
                        'watched_ms' => $watchedMs,
                        'played_at' => now(),
                    ]);
                } else {
                    $history->forceFill([
                        'position_ms' => $positionMs,
                        'watched_ms' => min(
                            $aggregationSeconds * 1000,
                            max(0, (int) $history->watched_ms) + $watchedMs,
                        ),
                        'played_at' => now(),
                    ])->save();
                }
            }

            if ($completed && ! $wasCompleted) {
                PlaybackHistory::query()->create([
                    'user_id' => $request->user()->getKey(),
                    'media_item_id' => $item->getKey(),
                    'event' => 'completed',
                    'position_ms' => $positionMs,
                    'watched_ms' => $watchedMs,
                    'played_at' => now(),
                ]);
            }

            return [$progress, true];
        }, 3);

        return response()->json([
            'accepted' => $accepted,
            'sequence' => $progress->sequence,
        ]);
    }
}
