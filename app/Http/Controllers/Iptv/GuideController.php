<?php

namespace App\Http\Controllers\Iptv;

use App\Http\Controllers\Controller;
use App\Models\Iptv\Channel;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\View\View;

class GuideController extends Controller
{
    public function __invoke(Request $request): View
    {
        $start = CarbonImmutable::now()->startOfHour();
        $end = $start->addHours(6);
        $channels = Channel::query()
            ->where('is_active', true)
            ->whereHas(
                'provider',
                fn ($query) => $query->where('enabled', true),
            )
            ->where(function ($query): void {
                $query->whereNull('channel_group_id')
                    ->orWhereHas(
                        'group',
                        fn ($query) => $query->where('is_active', true),
                    );
            })
            ->when($request->filled('group'), fn ($q) => $q->where('channel_group_id', $request->integer('group')))
            ->with(['group', 'programs' => fn ($q) => $q->where('ends_at', '>', $start)->where('starts_at', '<', $end)->orderBy('starts_at')])
            ->orderBy('name')->limit(100)->get();

        return view('iptv.guide', compact('channels', 'start', 'end'));
    }
}
