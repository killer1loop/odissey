<?php

namespace App\Services\Iptv;

use App\Jobs\Iptv\SyncXtreamSeries;
use App\Jobs\Media\EnrichMediaItem;
use App\Models\Iptv\IptvProvider;
use App\Models\MediaItem;
use App\Models\MediaSource;
use App\Models\User;
use App\Services\Iptv\Exceptions\SanitizedIptvException;
use App\Services\Media\ArtworkMetadataMerger;
use App\Services\Media\TrustedArtworkUrl;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

class XtreamVodCatalogSynchronizer
{
    public function __construct(
        private readonly TrustedArtworkUrl $artworkUrls,
        private readonly ArtworkMetadataMerger $artworkMetadata,
    ) {}

    /**
     * @param  array<int, array<string, mixed>>  $vodCategories
     * @param  array<int, array<string, mixed>>  $movies
     * @param  array<int, array<string, mixed>>  $seriesCategories
     * @param  array<int, array<string, mixed>>  $series
     * @return array{movies: int, series: int}
     */
    public function sync(
        IptvProvider $provider,
        array &$vodCategories,
        array &$movies,
        array &$seriesCategories,
        array &$series,
    ): array {
        $scanToken = (string) Str::ulid();
        $owner = User::query()
            ->where('is_admin', true)
            ->orderBy('id')
            ->firstOrFail();
        $sourceAttributes = [
            'name' => Str::limit(
                $provider->name.' · IPTV #'.$provider->id,
                255,
                '',
            ),
            'type' => MediaSource::TYPE_IPTV,
            'configuration' => ['managed' => true],
            'capabilities' => [
                'range' => true,
                'seekable' => true,
                'read_only' => true,
            ],
            'enabled' => $provider->enabled,
            'allow_private_network' => false,
            'scan_status' => 'scanning',
            'active_scan_token' => $scanToken,
            'scan_discovery_complete' => false,
            'scan_discovered' => 0,
            'scan_processed' => 0,
            'scan_failed' => 0,
            'scan_caption_jobs' => 0,
            'last_error_code' => null,
        ];
        $source = MediaSource::query()->firstOrCreate(
            ['iptv_provider_id' => $provider->id],
            $sourceAttributes,
        );
        $hasEstablishedMovies = $source->items()
            ->whereNull('missing_at')
            ->where('metadata->xtream_type', 'movie')
            ->exists();
        $hasEstablishedSeries = $source->items()
            ->whereNull('missing_at')
            ->where('metadata->xtream_type', 'series')
            ->exists();
        $hasEstablishedCategorizedMovies = $source->items()
            ->whereNull('missing_at')
            ->where('metadata->xtream_type', 'movie')
            ->whereNotNull('metadata->category')
            ->exists();
        $hasEstablishedCategorizedSeries = $source->items()
            ->whereNull('missing_at')
            ->where('metadata->xtream_type', 'series')
            ->whereNotNull('metadata->category')
            ->exists();
        $movieCategories = $this->categories($vodCategories);
        $showCategories = $this->categories($seriesCategories);

        if ($movieCategories === [] && $hasEstablishedCategorizedMovies) {
            throw new SanitizedIptvException(
                'provider_vod_categories_empty',
            );
        }

        if ($showCategories === [] && $hasEstablishedCategorizedSeries) {
            throw new SanitizedIptvException(
                'provider_series_categories_empty',
            );
        }

        $vodCategories = [];
        $seriesCategories = [];
        $movieRows = [];
        $seriesRows = [];

        foreach ($movies as $position => $movie) {
            unset($movies[$position]);
            $row = $this->movieRow(
                $source,
                $owner,
                $movie,
                $movieCategories,
                $scanToken,
            );
            if ($row !== null) {
                $movieRows[] = $row;
            }
        }

        foreach ($series as $position => $show) {
            unset($series[$position]);
            $row = $this->seriesRow(
                $source,
                $owner,
                $show,
                $showCategories,
                $scanToken,
            );
            if ($row !== null) {
                $seriesRows[] = $row;
            }
        }

        if (
            ($movieRows === [] && $hasEstablishedMovies)
            || ($seriesRows === [] && $hasEstablishedSeries)
        ) {
            throw new SanitizedIptvException(
                'provider_vod_catalog_empty',
            );
        }

        $source->forceFill($sourceAttributes)->save();
        $combinedRows = array_merge($movieRows, $seriesRows);

        try {
            foreach (array_chunk($combinedRows, 250) as $rows) {
                DB::transaction(function () use ($source, $rows): void {
                    $rows = $this->preserveArtworkMetadata($source, $rows);
                    DB::table('media_items')->upsert(
                        $rows,
                        ['media_source_id', 'stable_id'],
                        [
                            'scan_token',
                            'title',
                            'source_locator',
                            'relative_path',
                            'mime_type',
                            'container',
                            'requires_transcode',
                            'source_modified_at',
                            'missing_at',
                            'metadata',
                            'updated_at',
                        ],
                    );
                });
            }
        } catch (Throwable $exception) {
            // Keep the previously synced catalog visible and record the
            // failure instead of holding one long exclusive write transaction.
            $source->forceFill([
                'scan_status' => 'failed',
                'active_scan_token' => null,
                'last_error_code' => 'provider_vod_catalog_write_failed',
            ])->save();

            throw $exception;
        }

        DB::transaction(function () use (
            $source,
            $scanToken,
            $seriesRows,
        ): void {
            MediaItem::query()
                ->whereBelongsTo($source, 'source')
                ->whereIn('metadata->xtream_type', ['movie', 'series'])
                ->where(function ($query) use ($scanToken): void {
                    $query->whereNull('scan_token')
                        ->orWhere('scan_token', '!=', $scanToken);
                })
                ->update(['missing_at' => now()]);

            $source->forceFill([
                'scan_status' => $seriesRows === []
                    ? 'ready'
                    : 'scanning',
                'active_scan_token' => $seriesRows === []
                    ? null
                    : $scanToken,
                'scan_discovery_complete' => true,
                'scan_discovered' => count($seriesRows),
                'scan_processed' => 0,
                'scan_failed' => 0,
                'last_error_code' => null,
                'last_scanned_at' => $seriesRows === [] ? now() : null,
            ])->save();
        });

        $this->dispatchEnrichment(
            $source,
            $combinedRows,
            $seriesRows,
            $scanToken,
        );

        return [
            'movies' => count($movieRows),
            'series' => count($seriesRows),
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<int, array<string, mixed>>
     */
    private function preserveArtworkMetadata(
        MediaSource $source,
        array $rows,
    ): array {
        $existing = MediaItem::query()
            ->whereBelongsTo($source, 'source')
            ->whereIn('stable_id', array_column($rows, 'stable_id'))
            ->get(['stable_id', 'metadata'])
            ->keyBy('stable_id');

        foreach ($rows as &$row) {
            $item = $existing->get($row['stable_id']);
            if (! $item instanceof MediaItem) {
                continue;
            }

            $metadata = json_decode(
                $row['metadata'],
                true,
                512,
                JSON_THROW_ON_ERROR,
            );
            $row['metadata'] = json_encode(
                $this->artworkMetadata->merge(
                    $item->metadata ?? [],
                    $metadata,
                ),
                JSON_THROW_ON_ERROR,
            );
        }
        unset($row);

        return $rows;
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @param  array<int, array<string, mixed>>  $seriesRows
     */
    private function dispatchEnrichment(
        MediaSource $source,
        array $rows,
        array $seriesRows,
        string $scanToken,
    ): void {
        $maximumMetadataJobs = min(
            50000,
            max(0, (int) config('iptv.vod_metadata_jobs_per_sync', 10000)),
        );

        collect($rows)
            ->pluck('stable_id')
            ->take($maximumMetadataJobs)
            ->chunk(500)
            ->each(function ($stableIds) use ($source): void {
                MediaItem::query()
                    ->whereBelongsTo($source, 'source')
                    ->whereIn('stable_id', $stableIds->all())
                    ->pluck('id')
                    ->each(fn (string $id) => EnrichMediaItem::dispatch($id));
            });

        foreach ($seriesRows as $row) {
            $metadata = json_decode($row['metadata'], true);
            SyncXtreamSeries::dispatch(
                $source->iptv_provider_id,
                $source->id,
                (string) $metadata['xtream_series_id'],
                (string) $metadata['series_title'],
                $scanToken,
            );
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $categories
     * @return array<string, string>
     */
    private function categories(array $categories): array
    {
        $map = [];
        foreach ($categories as $category) {
            if (! is_array($category)) {
                continue;
            }
            $id = $this->scalarId($category['category_id'] ?? null);
            $name = $this->text($category['category_name'] ?? null);
            if ($id !== null && $name !== null) {
                $map[$id] = $name;
            }
        }

        return $map;
    }

    /**
     * @param  array<string, mixed>  $movie
     * @param  array<string, string>  $categories
     * @return array<string, mixed>|null
     */
    private function movieRow(
        MediaSource $source,
        User $owner,
        array $movie,
        array $categories,
        string $scanToken,
    ): ?array {
        $id = $this->scalarId($movie['stream_id'] ?? null);
        $name = $this->text($movie['name'] ?? null);
        if ($id === null || $name === null) {
            return null;
        }

        $extension = $this->extension(
            $movie['container_extension'] ?? 'mp4',
        );
        $categoryId = $this->scalarId($movie['category_id'] ?? null);
        $metadata = array_filter([
            'kind' => 'movie',
            'xtream_type' => 'movie',
            'xtream_stream_id' => $id,
            'category' => $categoryId === null
                ? null
                : ($categories[$categoryId] ?? null),
            'summary' => $this->text(
                $movie['plot'] ?? $movie['description'] ?? null,
                4000,
            ),
            'year' => $this->year(
                $movie['year'] ?? $movie['releaseDate'] ?? null,
            ),
            'rating' => $this->rating($movie['rating'] ?? null),
            'genres' => $this->genres($movie['genre'] ?? null),
            'tmdb_id' => $this->positiveInteger(
                $movie['tmdb'] ?? $movie['tmdb_id'] ?? null,
            ),
            'poster_url' => $this->artworkUrls->normalize(
                $movie['stream_icon'] ?? null,
            ),
        ], fn (mixed $value): bool => $value !== null && $value !== []);

        return $this->row(
            source: $source,
            owner: $owner,
            stableSeed: 'xtream:movie:'.$id,
            scanToken: $scanToken,
            title: $name,
            locator: ['type' => 'movie', 'id' => $id, 'extension' => $extension],
            path: 'Movies/'.$name.'.'.$extension,
            extension: $extension,
            metadata: $metadata,
            added: $movie['added'] ?? null,
        );
    }

    /**
     * @param  array<string, mixed>  $show
     * @param  array<string, string>  $categories
     * @return array<string, mixed>|null
     */
    private function seriesRow(
        MediaSource $source,
        User $owner,
        array $show,
        array $categories,
        string $scanToken,
    ): ?array {
        $id = $this->scalarId($show['series_id'] ?? null);
        $name = $this->text($show['name'] ?? null);
        if ($id === null || $name === null) {
            return null;
        }

        $categoryId = $this->scalarId($show['category_id'] ?? null);
        $metadata = array_filter([
            'kind' => 'series',
            'xtream_type' => 'series',
            'xtream_series_id' => $id,
            'series_title' => $name,
            'category' => $categoryId === null
                ? null
                : ($categories[$categoryId] ?? null),
            'summary' => $this->text(
                $show['plot'] ?? $show['description'] ?? null,
                4000,
            ),
            'year' => $this->year(
                $show['releaseDate'] ?? $show['year'] ?? null,
            ),
            'rating' => $this->rating($show['rating'] ?? null),
            'genres' => $this->genres($show['genre'] ?? null),
            'tmdb_id' => $this->positiveInteger(
                $show['tmdb'] ?? $show['tmdb_id'] ?? null,
            ),
            'poster_url' => $this->artworkUrls->normalize(
                $show['cover'] ?? null,
            ),
            'backdrop_url' => $this->artworkUrls->first(
                $show['backdrop_path'] ?? null,
            ),
        ], fn (mixed $value): bool => $value !== null && $value !== []);

        return $this->row(
            source: $source,
            owner: $owner,
            stableSeed: 'xtream:series:'.$id,
            scanToken: $scanToken,
            title: $name,
            locator: ['type' => 'series', 'id' => $id, 'extension' => 'mp4'],
            path: 'TV Shows/'.$name,
            extension: null,
            metadata: $metadata,
            added: $show['last_modified'] ?? null,
        );
    }

    /**
     * @param  array{type: string, id: string, extension: string}  $locator
     * @param  array<string, mixed>  $metadata
     * @return array<string, mixed>
     */
    private function row(
        MediaSource $source,
        User $owner,
        string $stableSeed,
        string $scanToken,
        string $title,
        array $locator,
        string $path,
        ?string $extension,
        array $metadata,
        mixed $added,
    ): array {
        $now = now();

        return [
            'id' => (string) Str::ulid(),
            'user_id' => $owner->id,
            'media_source_id' => $source->id,
            'stable_id' => hash('sha256', $stableSeed),
            'scan_token' => $scanToken,
            'title' => $title,
            'media_kind' => 'video',
            'source_type' => MediaSource::TYPE_IPTV,
            'source_locator' => Crypt::encryptString(json_encode(
                $locator,
                JSON_THROW_ON_ERROR,
            )),
            'relative_path' => $path,
            'mime_type' => $this->mimeType($extension),
            'container' => $extension,
            'video_codec' => null,
            'audio_codec' => null,
            'duration_ms' => null,
            'requires_transcode' => $extension === null
                ? false
                : ! in_array($extension, ['mp4', 'm4v', 'webm'], true),
            'size_bytes' => null,
            'source_modified_at' => $this->timestamp($added),
            'missing_at' => null,
            'metadata' => json_encode($metadata, JSON_THROW_ON_ERROR),
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }

    private function scalarId(mixed $value): ?string
    {
        if (! is_string($value) && ! is_int($value)) {
            return null;
        }
        $value = trim((string) $value);

        return $value === '' || strlen($value) > 255 ? null : $value;
    }

    private function text(mixed $value, int $limit = 255): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }
        $value = preg_replace('/[\x00-\x1F\x7F]/u', '', trim((string) $value));

        return is_string($value) && $value !== ''
            ? Str::limit($value, $limit, '')
            : null;
    }

    private function extension(mixed $value): string
    {
        $extension = strtolower(trim((string) $value));

        return preg_match('/^[a-z0-9]{1,8}$/', $extension) === 1
            ? $extension
            : 'mp4';
    }

    private function year(mixed $value): ?int
    {
        preg_match('/\b(19|20)\d{2}\b/', (string) $value, $match);
        $year = isset($match[0]) ? (int) $match[0] : 0;

        return $year >= 1900 && $year <= ((int) date('Y') + 2) ? $year : null;
    }

    private function rating(mixed $value): ?float
    {
        if (! is_numeric($value)) {
            return null;
        }
        $rating = (float) $value;

        return $rating >= 0 && $rating <= 10 ? $rating : null;
    }

    /** @return array<int, string> */
    private function genres(mixed $value): array
    {
        if (! is_string($value)) {
            return [];
        }

        return array_slice(array_values(array_filter(array_map(
            fn (string $genre): ?string => $this->text($genre, 80),
            preg_split('/[,|\/]/', $value) ?: [],
        ))), 0, 20);
    }

    private function positiveInteger(mixed $value): ?int
    {
        return (is_int($value) || is_string($value))
            && ctype_digit((string) $value)
            && (int) $value > 0
                ? (int) $value
                : null;
    }

    private function timestamp(mixed $value): ?string
    {
        if (
            (! is_int($value) && ! is_string($value))
            || ! ctype_digit((string) $value)
            || (int) $value < 1
        ) {
            return null;
        }

        return date('Y-m-d H:i:s', (int) $value);
    }

    private function mimeType(?string $extension): ?string
    {
        return match ($extension) {
            'mp4', 'm4v' => 'video/mp4',
            'webm' => 'video/webm',
            'mkv' => 'video/x-matroska',
            'avi' => 'video/x-msvideo',
            'ts', 'm2ts' => 'video/mp2t',
            null => null,
            default => 'application/octet-stream',
        };
    }
}
