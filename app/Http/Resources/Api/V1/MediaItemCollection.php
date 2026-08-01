<?php

namespace App\Http\Resources\Api\V1;

use App\Models\MediaItem;
use App\Services\Media\MediaArtworkAvailability;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\ResourceCollection;

class MediaItemCollection extends ResourceCollection
{
    public $collects = MediaItemResource::class;

    public function toArray(Request $request): array
    {
        app(MediaArtworkAvailability::class)->prepare(
            $request,
            $this->collection
                ->map(fn (MediaItemResource $resource): mixed => (
                    $resource->resource
                ))
                ->filter(fn (mixed $item): bool => $item instanceof MediaItem),
        );

        return parent::toArray($request);
    }
}
