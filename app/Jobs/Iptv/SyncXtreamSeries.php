<?php

namespace App\Jobs\Iptv;

use App\Jobs\Media\EnrichMediaItem;
use App\Models\Iptv\IptvProvider;
use App\Models\MediaItem;
use App\Models\MediaSource;
use App\Services\Iptv\XtreamClient;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Str;

class SyncXtreamSeries implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $timeout = 300;

    public int $tries = 3;

    public int $uniqueFor = 900;

    public function __construct(
        public readonly int $providerId,
        public readonly string $sourceId,
        public readonly string $seriesId,
        public readonly string $seriesTitle,
    ) {
        $this->onQueue('iptv-vod');
    }

    public function uniqueId(): string
    {
        return $this->providerId.':'.$this->seriesId;
    }

    public function middleware(): array
    {
        return [
            (new WithoutOverlapping(
                'iptv-vod-series:'.$this->providerId.':'.$this->seriesId,
            ))->expireAfter(330),
        ];
    }

    public function handle(XtreamClient $client): void
    {
        $provider = IptvProvider::query()->find($this->providerId);
        $source = MediaSource::query()
            ->whereKey($this->sourceId)
            ->where('iptv_provider_id', $this->providerId)
            ->first();
        if ($provider === null || ! $provider->enabled || $source === null) {
            return;
        }

        $payload = $client->seriesInfo($provider, $this->seriesId);
        $episodes = $this->episodes($payload['episodes']);
        $maximum = min(
            10000,
            max(1, (int) config('iptv.vod_episode_max_rows', 2500)),
        );
        if (count($episodes) > $maximum) {
            throw new \RuntimeException('provider_vod_episode_limit');
        }

        $ownerId = MediaItem::query()
            ->whereBelongsTo($source, 'source')
            ->where('stable_id', hash(
                'sha256',
                'xtream:series:'.$this->seriesId,
            ))
            ->value('user_id');
        if ($ownerId === null) {
            return;
        }

        $scanToken = (string) Str::ulid();
        $itemIds = [];
        foreach ($episodes as $entry) {
            $id = $this->scalarId($entry['id'] ?? $entry['stream_id'] ?? null);
            $season = $this->positiveInteger(
                $entry['_season']
                    ?? $entry['season']
                    ?? data_get($entry, 'info.season'),
            );
            $number = $this->positiveInteger(
                $entry['episode_num']
                    ?? data_get($entry, 'info.episode_num')
                    ?? data_get($entry, 'info.episode'),
            );
            if ($id === null || $season === null || $number === null) {
                continue;
            }

            $extension = $this->extension(
                $entry['container_extension']
                    ?? data_get($entry, 'info.container_extension')
                    ?? 'mp4',
            );
            $episodeTitle = $this->text(
                $entry['title']
                    ?? data_get($entry, 'info.name')
                    ?? data_get($entry, 'info.title'),
            ) ?? 'Episode '.$number;
            $metadata = array_filter([
                'kind' => 'episode',
                'xtream_type' => 'episode',
                'xtream_series_id' => $this->seriesId,
                'xtream_stream_id' => $id,
                'series_title' => $this->seriesTitle,
                'season_number' => $season,
                'episode_number' => $number,
                'summary' => $this->text(
                    data_get($entry, 'info.plot')
                        ?? data_get($entry, 'info.description'),
                    4000,
                ),
                'duration' => $this->text(
                    data_get($entry, 'info.duration'),
                    64,
                ),
                'rating' => $this->rating(
                    data_get($entry, 'info.rating'),
                ),
            ], fn (mixed $value): bool => $value !== null && $value !== []);
            $item = MediaItem::query()->updateOrCreate(
                [
                    'media_source_id' => $source->id,
                    'stable_id' => hash('sha256', 'xtream:episode:'.$id),
                ],
                [
                    'user_id' => $ownerId,
                    'scan_token' => $scanToken,
                    'title' => $episodeTitle,
                    'media_kind' => 'video',
                    'source_type' => MediaSource::TYPE_IPTV,
                    'source_locator' => json_encode([
                        'type' => 'episode',
                        'id' => $id,
                        'extension' => $extension,
                    ], JSON_THROW_ON_ERROR),
                    'relative_path' => sprintf(
                        'TV Shows/%s/Season %02d/%s - S%02dE%02d.%s',
                        $this->seriesTitle,
                        $season,
                        $this->seriesTitle,
                        $season,
                        $number,
                        $extension,
                    ),
                    'mime_type' => $this->mimeType($extension),
                    'container' => $extension,
                    'requires_transcode' => ! in_array(
                        $extension,
                        ['mp4', 'm4v', 'webm'],
                        true,
                    ),
                    'missing_at' => null,
                    'metadata' => $metadata,
                ],
            );
            $itemIds[] = $item->id;
        }

        MediaItem::query()
            ->whereBelongsTo($source, 'source')
            ->where('metadata->kind', 'episode')
            ->where('metadata->xtream_series_id', $this->seriesId)
            ->where(function ($query) use ($scanToken): void {
                $query->whereNull('scan_token')
                    ->orWhere('scan_token', '!=', $scanToken);
            })
            ->update(['missing_at' => now()]);

        foreach ($itemIds as $itemId) {
            EnrichMediaItem::dispatch($itemId);
        }
    }

    /**
     * @param  array<int|string, mixed>  $payload
     * @return array<int, array<string, mixed>>
     */
    private function episodes(array $payload): array
    {
        $episodes = [];

        foreach ($payload as $seasonKey => $group) {
            if (! is_array($group)) {
                continue;
            }

            if (
                array_key_exists('id', $group)
                || array_key_exists('stream_id', $group)
            ) {
                $group['_season'] ??= is_numeric($seasonKey)
                    ? (int) $seasonKey
                    : null;
                $episodes[] = $group;

                continue;
            }

            foreach ($group as $episode) {
                if (! is_array($episode)) {
                    continue;
                }
                $episode['_season'] ??= is_numeric($seasonKey)
                    ? (int) $seasonKey
                    : null;
                $episodes[] = $episode;
            }
        }

        return $episodes;
    }

    private function scalarId(mixed $value): ?string
    {
        if (! is_string($value) && ! is_int($value)) {
            return null;
        }
        $value = trim((string) $value);

        return $value === '' || strlen($value) > 255 ? null : $value;
    }

    private function positiveInteger(mixed $value): ?int
    {
        return (is_int($value) || is_string($value))
            && ctype_digit((string) $value)
            && (int) $value > 0
                ? (int) $value
                : null;
    }

    private function extension(mixed $value): string
    {
        $extension = strtolower(trim((string) $value));

        return preg_match('/^[a-z0-9]{1,8}$/', $extension) === 1
            ? $extension
            : 'mp4';
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

    private function rating(mixed $value): ?float
    {
        if (! is_numeric($value)) {
            return null;
        }
        $rating = (float) $value;

        return $rating >= 0 && $rating <= 10 ? $rating : null;
    }

    private function mimeType(string $extension): string
    {
        return match ($extension) {
            'mp4', 'm4v' => 'video/mp4',
            'webm' => 'video/webm',
            'mkv' => 'video/x-matroska',
            'avi' => 'video/x-msvideo',
            'ts', 'm2ts' => 'video/mp2t',
            default => 'application/octet-stream',
        };
    }
}
