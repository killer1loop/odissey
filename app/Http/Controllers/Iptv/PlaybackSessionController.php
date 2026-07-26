<?php

namespace App\Http\Controllers\Iptv;

use App\Http\Controllers\Controller;
use App\Models\Iptv\Channel;
use App\Models\Iptv\IptvPlaybackSession;
use App\Services\Iptv\PlaybackAccess;
use App\Services\Iptv\PlaybackSessionManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class PlaybackSessionController extends Controller
{
    public function store(
        Request $request,
        Channel $channel,
        PlaybackSessionManager $sessions,
    ): RedirectResponse {
        $channel->loadMissing(['provider', 'group']);
        abort_unless(
            $channel->is_active
            && $channel->provider->enabled
            && ($channel->group === null || $channel->group->is_active),
            404,
        );

        $session = $sessions->create($request->user(), $channel);

        return redirect()->route('iptv.playback.show', $session);
    }

    public function show(
        Request $request,
        IptvPlaybackSession $session,
        PlaybackAccess $access,
    ): View {
        $access->assertSession($request->user(), $session);
        $session->loadMissing('channel.group');

        $programs = $session->channel->programs()
            ->where('ends_at', '>', now())
            ->orderBy('starts_at')
            ->limit(2)
            ->get();

        return view('iptv.playback.show', compact('session', 'programs'));
    }

    public function destroy(
        Request $request,
        IptvPlaybackSession $session,
        PlaybackAccess $access,
        PlaybackSessionManager $sessions,
    ): RedirectResponse {
        $access->assertSession($request->user(), $session);
        $sessions->release($session);

        return redirect()->route('iptv.channels.index');
    }
}
