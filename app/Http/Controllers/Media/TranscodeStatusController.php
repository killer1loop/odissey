<?php

namespace App\Http\Controllers\Media;

use App\Http\Controllers\Controller;
use App\Models\MediaItem;
use App\Models\TranscodeSession;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

class TranscodeStatusController extends Controller
{
    public function __invoke(Request $request, string $media, string $session): View
    {
        $item = MediaItem::query()
            ->accessibleTo($request->user())
            ->findOrFail($media);
        $session = TranscodeSession::query()
            ->whereBelongsTo($request->user())
            ->whereBelongsTo($item, 'mediaItem')
            ->findOrFail($session);
        $progress = $item->progress()
            ->whereBelongsTo($request->user())
            ->first();

        return view('media.partials.transcode-status', compact('item', 'session', 'progress'));
    }
}
