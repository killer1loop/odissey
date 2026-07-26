<?php

namespace App\Http\Controllers\Iptv;

use App\Http\Controllers\Controller;
use App\Models\Iptv\Channel;
use App\Models\Iptv\ChannelFavorite;
use App\Models\Iptv\ChannelGroup;
use App\Models\Iptv\EpgProgram;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ChannelBrowserController extends Controller
{
    public function __invoke(Request $request): View
    {
        $search = mb_substr(trim((string) $request->query('q')), 0, 100);
        $groupId = $request->integer('group') ?: null;
        $favoritesOnly = $request->boolean('favorites');
        $viewMode = $request->query('view') === 'channels' ? 'channels' : 'guide';
        $guideNow = CarbonImmutable::now();
        $guideStart = $guideNow->startOfHour();
        $guideEnd = $guideStart->addHours(6);
        $favoriteIds = ChannelFavorite::query()
            ->where('user_id', $request->user()->id)
            ->pluck('channel_id');

        $channels = Channel::query()
            ->select('channels.*')
            ->join('iptv_providers', 'iptv_providers.id', '=', 'channels.iptv_provider_id')
            ->where('channels.is_active', true)
            ->where('iptv_providers.enabled', true)
            ->where(function ($query): void {
                $query->whereNull('channels.channel_group_id')
                    ->orWhereHas(
                        'group',
                        fn ($group) => $group->where('is_active', true),
                    );
            })
            ->when($groupId, fn ($query) => $query->where('channel_group_id', $groupId))
            ->when(
                $favoritesOnly,
                fn ($query) => $query->whereIn('channels.id', $favoriteIds),
            )
            ->when($search !== '', function ($query) use ($search): void {
                $escaped = addcslashes($search, '\\%_');
                $query->whereRaw(
                    "channels.name LIKE ? ESCAPE '\\'",
                    ['%'.$escaped.'%'],
                );
            })
            ->with('group:id,name')
            ->orderByRaw(
                "CASE WHEN channels.channel_number GLOB '[0-9]*' THEN CAST(channels.channel_number AS INTEGER) ELSE 2147483647 END"
            )
            ->orderBy('channels.name')
            ->paginate($viewMode === 'guide' ? 80 : 48)
            ->withQueryString();

        $programs = EpgProgram::query()
            ->whereIn('channel_id', $channels->getCollection()->pluck('id'))
            ->where('ends_at', '>', $viewMode === 'guide' ? $guideStart : $guideNow)
            ->where('starts_at', '<', $viewMode === 'guide' ? $guideEnd : $guideNow->addHours(8))
            ->orderBy('starts_at')
            ->get()
            ->groupBy('channel_id');

        return view('iptv.channels.index', [
            'channels' => $channels,
            'groups' => ChannelGroup::query()
                ->where('is_active', true)
                ->whereHas('provider', fn ($query) => $query->where('enabled', true))
                ->whereHas('channels', fn ($query) => $query->where('is_active', true))
                ->withCount(['channels' => fn ($query) => $query->where('is_active', true)])
                ->orderBy('sort_order')
                ->orderBy('name')
                ->get(),
            'favoriteIds' => $favoriteIds,
            'guideByChannel' => $programs,
            'selectedGroup' => $groupId,
            'search' => $search,
            'favoritesOnly' => $favoritesOnly,
            'viewMode' => $viewMode,
            'guideNow' => $guideNow,
            'guideStart' => $guideStart,
            'guideEnd' => $guideEnd,
            'viewerTimezone' => $request->user()->timezone ?: config('app.timezone'),
        ]);
    }
}
