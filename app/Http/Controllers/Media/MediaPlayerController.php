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

        return view('media.show', compact('item', 'progress', 'session'));
    }
}
