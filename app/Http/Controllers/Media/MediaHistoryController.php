<?php

namespace App\Http\Controllers\Media;

use App\Http\Controllers\Controller;
use App\Models\PlaybackHistory;
use Illuminate\Http\Request;
use Illuminate\View\View;

class MediaHistoryController extends Controller
{
    public function __invoke(Request $request): View
    {
        $history = PlaybackHistory::query()
            ->whereBelongsTo($request->user())
            ->with('mediaItem')
            ->latest('played_at')
            ->limit(250)
            ->get()
            ->groupBy('media_item_id')
            ->map(fn ($events) => [
                'item' => $events->first()->mediaItem,
                'last_played_at' => $events->max('played_at'),
                'watched_ms' => $events->sum('watched_ms'),
                'completed' => $events->contains('event', 'completed'),
            ])
            ->sortByDesc('last_played_at');

        return view('media.history', compact('history'));
    }
}
