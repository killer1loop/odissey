<?php

namespace App\Http\Controllers\Media;

use App\Http\Controllers\Controller;
use App\Models\MediaItem;
use App\Models\PlaybackHistory;
use App\Models\TranscodeSession;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

class MediaPlayerController extends Controller
{
    public function __invoke(Request $request, string $media): View
    {
        $item = MediaItem::query()
            ->whereBelongsTo($request->user())
            ->findOrFail($media);

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

        return view('media.show', compact('item', 'progress', 'session'));
    }
}
