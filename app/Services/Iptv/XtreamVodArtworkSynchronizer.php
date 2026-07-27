<?php

namespace App\Services\Iptv;

use App\Models\Iptv\IptvProvider;
use App\Models\MediaItem;
use App\Models\MediaSource;
use App\Services\Media\TrustedArtworkUrl;

class XtreamVodArtworkSynchronizer
{
    public function __construct(
        private readonly XtreamClient $client,
        private readonly TrustedArtworkUrl $artworkUrls,
    ) {}

    /**
     * @return array{movies: int, series: int, updated: int}
     */
    public function sync(IptvProvider $provider): array
    {
        $source = MediaSource::query()
            ->where('iptv_provider_id', $provider->id)
            ->first();
        if ($source === null) {
            return ['movies' => 0, 'series' => 0, 'updated' => 0];
        }

        $movies = $this->client->vodStreams($provider);
        $movieResult = $this->syncRows(
            $source,
            $movies,
            'movie',
        );
        unset($movies);

        $series = $this->client->series($provider);
        $seriesResult = $this->syncRows(
            $source,
            $series,
            'series',
        );
        unset($series);

        return [
            'movies' => $movieResult['candidates'],
            'series' => $seriesResult['candidates'],
            'updated' => $movieResult['updated'] + $seriesResult['updated'],
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array{candidates: int, updated: int}
     */
    private function syncRows(
        MediaSource $source,
        array &$rows,
        string $kind,
    ): array {
        $candidates = 0;
        $updated = 0;
        $chunk = [];

        foreach ($rows as $position => $row) {
            unset($rows[$position]);
            if (! is_array($row)) {
                continue;
            }

            $id = $this->scalarId(
                $kind === 'movie'
                    ? ($row['stream_id'] ?? null)
                    : ($row['series_id'] ?? null),
            );
            if ($id === null) {
                continue;
            }

            $artwork = array_filter([
                'poster_url' => $this->artworkUrls->normalize(
                    $kind === 'movie'
                        ? ($row['stream_icon'] ?? null)
                        : ($row['cover'] ?? null),
                ),
                'backdrop_url' => $kind === 'series'
                    ? $this->artworkUrls->first(
                        $row['backdrop_path'] ?? null,
                    )
                    : null,
            ]);
            if ($artwork === []) {
                continue;
            }

            $candidates++;
            $chunk[hash('sha256', 'xtream:'.$kind.':'.$id)] = $artwork;
            if (count($chunk) === 250) {
                $updated += $this->applyChunk($source, $chunk);
                $chunk = [];
            }
        }

        if ($chunk !== []) {
            $updated += $this->applyChunk($source, $chunk);
        }

        return ['candidates' => $candidates, 'updated' => $updated];
    }

    /**
     * @param  array<string, array<string, string>>  $artworkByStableId
     */
    private function applyChunk(
        MediaSource $source,
        array $artworkByStableId,
    ): int {
        $updated = 0;
        $items = MediaItem::query()
            ->whereBelongsTo($source, 'source')
            ->whereIn('stable_id', array_keys($artworkByStableId))
            ->get();

        foreach ($items as $item) {
            $metadata = $item->metadata ?? [];
            $changed = false;

            foreach ($artworkByStableId[$item->stable_id] as $key => $url) {
                $cachedKey = str_replace('_url', '_cached', $key);
                $current = $metadata[$key] ?? null;
                if (($metadata[$cachedKey] ?? false) === true) {
                    if ($current === null) {
                        $metadata[$key] = $url;
                        $changed = true;
                    }

                    continue;
                }
                if ($current !== $url) {
                    $metadata[$key] = $url;
                    unset($metadata[$cachedKey]);
                    $changed = true;
                }
            }

            if ($changed) {
                $item->forceFill(['metadata' => $metadata])->saveQuietly();
                $updated++;
            }
        }

        return $updated;
    }

    private function scalarId(mixed $value): ?string
    {
        if (! is_int($value) && ! is_string($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' || strlen($value) > 255 ? null : $value;
    }
}
