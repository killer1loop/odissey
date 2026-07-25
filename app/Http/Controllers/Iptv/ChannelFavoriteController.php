<?php

namespace App\Http\Controllers\Iptv;

use App\Http\Controllers\Controller;
use App\Models\Iptv\Channel;
use App\Models\Iptv\ChannelFavorite;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class ChannelFavoriteController extends Controller
{
    public function store(Request $request, Channel $channel): Response
    {
        abort_unless($channel->is_active, 404);

        ChannelFavorite::query()->firstOrCreate([
            'user_id' => $request->user()->id,
            'channel_id' => $channel->id,
        ]);

        return response()->view('iptv.channels.favorite-button', [
            'channel' => $channel,
            'isFavorite' => true,
        ]);
    }

    public function destroy(Request $request, Channel $channel): Response
    {
        ChannelFavorite::query()
            ->where('user_id', $request->user()->id)
            ->where('channel_id', $channel->id)
            ->delete();

        return response()->view('iptv.channels.favorite-button', [
            'channel' => $channel,
            'isFavorite' => false,
        ]);
    }
}
