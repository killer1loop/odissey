<?php

namespace App\Services\Media;

use App\Models\MediaItem;
use App\Models\User;
use Illuminate\Support\Collection;

class MediaArtworkResolver
{
    /**
     * Resolve each item to itself or to its parent series artwork item.
     *
     * @param  iterable<int, MediaItem>  $items
     * @return Collection<string, MediaItem>
     */
    public function resolve(iterable $items, User $user): Collection
    {
        $items = collect($items)
            ->filter(fn (mixed $item): bool => $item instanceof MediaItem)
            ->values();
        $resolved = $items->keyBy(
            fn (MediaItem $item): string => (string) $item->getKey(),
        );
        $episodes = $items->filter(fn (MediaItem $item): bool => (
            ($item->metadata['kind'] ?? null) === 'episode'
            && $item->media_source_id !== null
            && ! $this->hasPoster($item)
        ));
        if ($episodes->isEmpty()) {
            return $resolved;
        }

        $sourceIds = $episodes->pluck('media_source_id')->filter()->unique();
        $seriesIds = $episodes
            ->map(fn (MediaItem $item): mixed => (
                $item->metadata['xtream_series_id'] ?? null
            ))
            ->filter(fn (mixed $value): bool => is_string($value) || is_int($value))
            ->map(fn (mixed $value): string => (string) $value)
            ->unique();
        $seriesTitles = $episodes
            ->map(fn (MediaItem $item): mixed => (
                $item->metadata['series_title'] ?? null
            ))
            ->filter(fn (mixed $value): bool => is_string($value) && $value !== '')
            ->unique();
        if ($seriesIds->isEmpty() && $seriesTitles->isEmpty()) {
            return $resolved;
        }

        $parents = MediaItem::query()
            ->accessibleTo($user)
            ->whereIn('media_source_id', $sourceIds)
            ->where('metadata->kind', 'series')
            ->where(function ($query) use ($seriesIds, $seriesTitles): void {
                if ($seriesIds->isNotEmpty()) {
                    $query->whereIn(
                        'metadata->xtream_series_id',
                        $seriesIds,
                    );
                }
                if ($seriesTitles->isNotEmpty()) {
                    $method = $seriesIds->isNotEmpty()
                        ? 'orWhereIn'
                        : 'whereIn';
                    $query->{$method}(
                        'metadata->series_title',
                        $seriesTitles,
                    );
                }
            })
            ->get()
            ->filter($this->hasPoster(...));
        $bySeriesId = $parents
            ->filter(fn (MediaItem $item): bool => isset(
                $item->metadata['xtream_series_id'],
            ))
            ->keyBy(fn (MediaItem $item): string => $this->key(
                (string) $item->media_source_id,
                (string) $item->metadata['xtream_series_id'],
            ));
        $byTitle = $parents
            ->filter(fn (MediaItem $item): bool => is_string(
                $item->metadata['series_title'] ?? null,
            ))
            ->keyBy(fn (MediaItem $item): string => $this->key(
                (string) $item->media_source_id,
                (string) $item->metadata['series_title'],
            ));

        foreach ($episodes as $episode) {
            $sourceId = (string) $episode->media_source_id;
            $seriesId = $episode->metadata['xtream_series_id'] ?? null;
            $title = $episode->metadata['series_title'] ?? null;
            $parent = (is_string($seriesId) || is_int($seriesId))
                ? $bySeriesId->get($this->key($sourceId, (string) $seriesId))
                : null;
            if ($parent === null && is_string($title)) {
                $parent = $byTitle->get($this->key($sourceId, $title));
            }
            if ($parent !== null) {
                $resolved->put((string) $episode->getKey(), $parent);
            }
        }

        return $resolved;
    }

    private function hasPoster(MediaItem $item): bool
    {
        return ($item->metadata['poster_cached'] ?? false) === true
            || (
                is_string($item->metadata['poster_url'] ?? null)
                && $item->metadata['poster_url'] !== ''
            );
    }

    private function key(string $sourceId, string $series): string
    {
        return $sourceId."\0".$series;
    }
}
