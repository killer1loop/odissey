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
            ->whereBelongsTo($request->user())
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

            if ($completed && ! $wasCompleted) {
                PlaybackHistory::query()->create([
                    'user_id' => $request->user()->getKey(),
                    'media_item_id' => $item->getKey(),
                    'event' => 'completed',
                    'position_ms' => $positionMs,
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
