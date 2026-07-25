<?php

namespace App\Services\Media;

use App\Models\MediaItem;

class PlaybackDecision
{
    public function for(MediaItem $item): string
    {
        if (in_array($item->container, ['mp4', 'mov'], true) && $item->video_codec === 'h264' && in_array($item->audio_codec, ['aac', 'mp3'], true)) {
            return 'direct';
        }
        if ($item->video_codec === 'h264' && in_array($item->audio_codec, ['aac', 'mp3'], true)) {
            return 'remux';
        }

        return 'transcode';
    }
}
