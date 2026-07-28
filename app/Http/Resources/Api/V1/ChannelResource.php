<?php

namespace App\Http\Resources\Api\V1;

use App\Models\Iptv\Channel;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Channel */
class ChannelResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->getKey(),
            'name' => $this->name,
            'channelNumber' => $this->channel_number,
            'group' => $this->when(
                $this->relationLoaded('group') && $this->group !== null,
                fn (): array => [
                    'id' => (string) $this->group->getKey(),
                    'name' => $this->group->name,
                ],
            ),
            'favorite' => $this->relationLoaded('favorites')
                && $this->favorites->isNotEmpty(),
            'iconUrl' => route(
                'api.v1.live.channels.icon',
                $this->getKey(),
            ),
            'programs' => EpgProgramResource::collection(
                $this->whenLoaded('programs'),
            ),
        ];
    }
}
