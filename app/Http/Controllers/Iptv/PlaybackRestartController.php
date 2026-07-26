<?php

namespace App\Http\Controllers\Iptv;

use App\Http\Controllers\Controller;
use App\Models\Iptv\IptvPlaybackSession;
use App\Services\Iptv\PlaybackSessionManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PlaybackRestartController extends Controller
{
    public function __invoke(
        Request $request,
        IptvPlaybackSession $session,
        PlaybackSessionManager $sessions,
    ): JsonResponse {
        abort_unless($session->user_id === $request->user()->id, 404);
        $session->loadMissing(['channel.provider', 'channel.group']);
        $channel = $session->channel;

        abort_unless(
            $channel->is_active
            && $channel->provider->enabled
            && ($channel->group === null || $channel->group->is_active),
            410,
            'Playback source unavailable.',
        );

        if (in_array($session->status, ['created', 'playing'], true)) {
            $sessions->release($session);
        }

        $replacement = $sessions->create($request->user(), $channel);

        return response()->json([
            'manifest_url' => route('iptv.playback.manifest', $replacement),
            'restart_url' => route('iptv.playback.restart', $replacement),
            'diagnostic_url' => route(
                'iptv.playback.diagnostics',
                $replacement,
            ),
        ], 201);
    }
}
