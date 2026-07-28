<?php

namespace App\Http\Resources\Api\V1;

use App\Models\Iptv\EpgProgram;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin EpgProgram */
class EpgProgramResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->getKey(),
            'channelId' => (string) $this->channel_id,
            'title' => $this->title,
            'description' => $this->description,
            'category' => $this->category,
            'startsAt' => $this->starts_at->utc()->toIso8601String(),
            'endsAt' => $this->ends_at->utc()->toIso8601String(),
        ];
    }
}
