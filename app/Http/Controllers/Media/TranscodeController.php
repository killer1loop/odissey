<?php

namespace App\Http\Controllers\Media;

use App\Http\Controllers\Controller;
use App\Jobs\Media\TranscodeMediaToHls;
use App\Models\MediaItem;
use App\Models\TranscodeSession;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TranscodeController extends Controller
{
    public function __invoke(Request $request, string $media): View|RedirectResponse
    {
        $item = MediaItem::query()
            ->whereBelongsTo($request->user())
            ->findOrFail($media);

        abort_unless($item->requires_transcode, 409);

        [$session, $created] = DB::transaction(function () use ($request, $item): array {
            $existing = TranscodeSession::query()
                ->whereBelongsTo($request->user())
                ->whereBelongsTo($item, 'mediaItem')
                ->whereIn('status', [
                    TranscodeSession::STATUS_PENDING,
                    TranscodeSession::STATUS_PROCESSING,
                    TranscodeSession::STATUS_READY,
                ])
                ->where(function ($query): void {
                    $query
                        ->whereNot('status', TranscodeSession::STATUS_READY)
                        ->orWhereNull('expires_at')
                        ->orWhere('expires_at', '>', now());
                })
                ->lockForUpdate()
                ->latest()
                ->first();

            if ($existing !== null) {
                return [$existing, false];
            }

            return [
                TranscodeSession::query()->create([
                    'user_id' => $request->user()->getKey(),
                    'media_item_id' => $item->getKey(),
                    'status' => TranscodeSession::STATUS_PENDING,
                ]),
                true,
            ];
        }, 3);

        if ($created) {
            TranscodeMediaToHls::dispatch($session->getKey())->afterCommit();
        }

        if ($request->header('HX-Request') === 'true') {
            $progress = $item->progress()
                ->whereBelongsTo($request->user())
                ->first();

            return view('media.partials.transcode-status', compact('item', 'session', 'progress'));
        }

        return redirect()->route('media.show', $item);
    }
}
