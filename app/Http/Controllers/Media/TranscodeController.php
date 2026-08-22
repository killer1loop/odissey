<?php

namespace App\Http\Controllers\Media;

use App\Http\Controllers\Controller;
use App\Jobs\Media\TranscodeMediaToHls;
use App\Models\MediaItem;
use App\Models\TranscodeSession;
use App\Services\Media\PlaybackDecision;
use App\Services\Media\TranscodeStorage;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TranscodeController extends Controller
{
    public function __invoke(
        Request $request,
        TranscodeStorage $storage,
        string $media,
    ): View|RedirectResponse {
        $selection = $request->validate([
            'profile' => ['nullable', 'in:auto,1080p,720p'],
            'audio_track' => ['nullable', 'integer', 'min:0', 'max:31'],
        ]);
        $item = MediaItem::query()
            ->accessibleTo($request->user())
            ->findOrFail($media);

        abort_unless($item->requires_transcode, 409);

        [$session, $created] = DB::transaction(function () use ($request, $item, $storage, $selection): array {
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

            if ($existing?->isAvailable() && ! $storage->hasCompleteOutput($existing)) {
                $storage->delete($existing);
                $existing->delete();
                $existing = null;
            }

            if ($existing !== null) {
                return [$existing, false];
            }

            $activeStatuses = [
                TranscodeSession::STATUS_PENDING,
                TranscodeSession::STATUS_PROCESSING,
            ];
            $perUserLimit = min(
                20,
                max(1, (int) config('odissey.max_pending_transcodes_per_user', 3)),
            );
            $globalLimit = min(
                500,
                max($perUserLimit, (int) config('odissey.max_pending_transcodes', 50)),
            );

            abort_if(
                TranscodeSession::query()
                    ->whereBelongsTo($request->user())
                    ->whereIn('status', $activeStatuses)
                    ->count() >= $perUserLimit,
                429,
                'Your transcode queue is full.',
            );
            abort_if(
                TranscodeSession::query()
                    ->whereIn('status', $activeStatuses)
                    ->count() >= $globalLimit,
                503,
                'The transcode queue is currently full.',
            );

            return [
                TranscodeSession::query()->create([
                    'user_id' => $request->user()->getKey(),
                    'media_item_id' => $item->getKey(),
                    'status' => TranscodeSession::STATUS_PENDING,
                    'profile' => $selection['profile'] ?? 'auto',
                    'delivery_mode' => app(PlaybackDecision::class)->deliveryModeFor($item),
                    'audio_track' => $selection['audio_track'] ?? null,
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
