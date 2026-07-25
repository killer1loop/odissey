<?php

namespace App\Services\Media\Captions;

use App\Models\MediaItem;

interface CaptionProvider
{
    /** @param list<string> $languages @return list<CaptionCandidate> */
    public function search(MediaItem $item, array $languages): array;
}
