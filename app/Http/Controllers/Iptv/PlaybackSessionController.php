<?php

namespace App\Http\Controllers\Iptv;

use App\Http\Controllers\Controller;
use App\Models\Iptv\Channel;
use App\Models\Iptv\EpgProgram;
use App\Models\Iptv\IptvPlaybackSession;
use App\Services\Iptv\PlaybackAccess;
use App\Services\Iptv\PlaybackSessionManager;
use Carbon\CarbonImmutable;
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
        $guideNow = CarbonImmutable::now();
        $guideEnd = $guideNow->addHours(2);

        $programs = $session->channel->programs()
            ->where('ends_at', '>', $guideNow)
            ->orderBy('starts_at')
            ->limit(12)
            ->get()
            ->unique(
                fn (EpgProgram $program): string => $this->guideIdentity($program),
            )
            ->take(2)
            ->values();

        $favoriteChannels = Channel::query()
            ->select('channels.*')
            ->join(
                'channel_favorites',
                'channel_favorites.channel_id',
                '=',
                'channels.id',
            )
            ->join(
                'iptv_providers',
                'iptv_providers.id',
                '=',
                'channels.iptv_provider_id',
            )
            ->where('channel_favorites.user_id', $request->user()->id)
            ->where('channels.is_active', true)
            ->where('iptv_providers.enabled', true)
            ->where(function ($query): void {
                $query->whereNull('channels.channel_group_id')
                    ->orWhereHas(
                        'group',
                        fn ($group) => $group->where('is_active', true),
                    );
            })
            ->with('group:id,name')
            ->orderBy('channel_favorites.created_at')
            ->orderBy('channels.name')
            ->get();
        $favoriteGuide = EpgProgram::query()
            ->whereIn('channel_id', $favoriteChannels->pluck('id'))
            ->where('ends_at', '>', $guideNow)
            ->where('starts_at', '<', $guideEnd)
            ->orderBy('starts_at')
            ->get()
            ->groupBy('channel_id')
            ->map(
                fn ($channelPrograms) => $channelPrograms
                    ->unique(
                        fn (EpgProgram $program): string => $this->guideIdentity($program),
                    )
                    ->values(),
            );

        return view('iptv.playback.show', [
            'session' => $session,
            'programs' => $programs,
            'favoriteChannels' => $favoriteChannels,
            'favoriteGuide' => $favoriteGuide,
            'guideNow' => $guideNow,
            'guideEnd' => $guideEnd,
            'viewerTimezone' => $request->user()->timezone ?: config('app.timezone'),
        ]);
    }

    private function guideIdentity(EpgProgram $program): string
    {
        return implode('|', [
            mb_strtolower(trim($program->title)),
            $program->starts_at->getTimestamp(),
        ]);
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
