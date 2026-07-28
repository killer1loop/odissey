<?php

namespace App\Http\Resources\Api\V1;

use App\Models\MediaItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin MediaItem */
class MediaItemResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $metadata = is_array($this->metadata) ? $this->metadata : [];
        $progress = $this->relationLoaded('progress')
            ? $this->progress
            : null;
        $favorite = $this->relationLoaded('favorites')
            && $this->favorites->isNotEmpty();

        return [
            'id' => (string) $this->getKey(),
            'title' => $this->title,
            'kind' => $this->normalizedKind($metadata),
            'mediaKind' => $this->media_kind,
            'durationMs' => $this->duration_ms,
            'favorite' => $favorite,
            'progress' => $progress === null ? null : [
                'positionMs' => (int) $progress->position_ms,
                'durationMs' => $progress->duration_ms,
                'completed' => (bool) $progress->completed,
                'sequence' => (int) $progress->sequence,
                'updatedAt' => $progress->updated_at?->utc()->toIso8601String(),
            ],
            'summary' => $this->safeMetadata($metadata),
            'technical' => [
                'container' => $this->container,
                'mimeType' => $this->mime_type,
                'videoCodec' => $this->video_codec,
                'audioCodec' => $this->audio_codec,
                'requiresTranscode' => (bool) $this->requires_transcode,
                'sizeBytes' => $this->size_bytes,
            ],
            'source' => $this->when(
                $this->relationLoaded('source') && $this->source !== null,
                fn (): array => [
                    'id' => (string) $this->source->getKey(),
                    'name' => $this->source->name,
                    'type' => $this->source->type,
                ],
            ),
            'artwork' => [
                'poster' => route(
                    'api.v1.media.artwork',
                    [$this->getKey(), 'poster'],
                ),
                'backdrop' => route(
                    'api.v1.media.artwork',
                    [$this->getKey(), 'backdrop'],
                ),
            ],
            'updatedAt' => $this->updated_at?->utc()->toIso8601String(),
        ];
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @return array<string, mixed>
     */
    private function safeMetadata(array $metadata): array
    {
        return array_filter([
            'overview' => $this->stringOrNull(
                $metadata['overview'] ?? $metadata['summary'] ?? null,
                5000,
            ),
            'year' => is_numeric($metadata['year'] ?? null)
                ? (int) $metadata['year']
                : null,
            'rating' => is_numeric($metadata['rating'] ?? null)
                ? (float) $metadata['rating']
                : null,
            'genres' => $this->stringList($metadata['genres'] ?? null),
            'seriesTitle' => $this->stringOrNull(
                $metadata['series_title'] ?? null,
                255,
            ),
            'seasonNumber' => is_numeric($metadata['season_number'] ?? null)
                ? (int) $metadata['season_number']
                : null,
            'episodeNumber' => is_numeric($metadata['episode_number'] ?? null)
                ? (int) $metadata['episode_number']
                : null,
            'artist' => $this->stringOrNull($metadata['artist'] ?? null, 255),
            'album' => $this->stringOrNull($metadata['album'] ?? null, 255),
            'trackNumber' => is_numeric($metadata['track_number'] ?? null)
                ? (int) $metadata['track_number']
                : null,
        ], static fn (mixed $value): bool => $value !== null);
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function normalizedKind(array $metadata): string
    {
        $kind = $metadata['kind'] ?? null;
        if (in_array($kind, ['movie', 'series', 'episode'], true)) {
            return $kind;
        }

        return $this->media_kind === 'music' ? 'track' : 'movie';
    }

    private function stringOrNull(mixed $value, int $limit): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return mb_substr(trim($value), 0, $limit);
    }

    /**
     * @return list<string>|null
     */
    private function stringList(mixed $value): ?array
    {
        if (is_string($value)) {
            $value = preg_split('/[,|]/', $value);
        }
        if (! is_array($value)) {
            return null;
        }

        $values = array_values(array_slice(array_filter(array_map(
            fn (mixed $entry): ?string => $this->stringOrNull($entry, 100),
            $value,
        )), 0, 20));

        return $values === [] ? null : $values;
    }
}
