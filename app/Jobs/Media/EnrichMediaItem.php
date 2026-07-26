<?php

namespace App\Jobs\Media;

use App\Models\MediaItem;
use App\Services\Media\ArtworkManager;
use App\Services\Media\TmdbMetadataProvider;
use App\Services\Media\TvmazeMetadataProvider;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

class EnrichMediaItem implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $timeout = 120;

    public int $tries = 2;

    public int $uniqueFor = 600;

    public function __construct(public readonly string $mediaItemId) {}

    public function uniqueId(): string
    {
        return $this->mediaItemId;
    }

    public function handle(
        TmdbMetadataProvider $tmdb,
        TvmazeMetadataProvider $tvmaze,
        ArtworkManager $artwork,
    ): void {
        $item = MediaItem::query()->find($this->mediaItemId);
        if ($item === null || $item->media_kind !== 'video') {
            return;
        }

        $parsed = $item->metadata ?? [];
        $kind = $parsed['kind'] ?? null;
        if (! in_array($kind, ['movie', 'series', 'episode'], true)) {
            return;
        }

        try {
            $tmdbMetadata = $tmdb->match($parsed);
            $tvMetadata = in_array($kind, ['series', 'episode'], true)
                ? $tvmaze->match($parsed)
                : [];
            if ($kind === 'episode' && $tvMetadata === []) {
                unset($tmdbMetadata['title']);
            }
            $metadata = array_filter(
                array_merge($parsed, $tmdbMetadata, $tvMetadata),
                fn (mixed $value): bool => $value !== null
                    && $value !== ''
                    && $value !== [],
            );
            $item->forceFill([
                'title' => $metadata['title'] ?? $item->title,
                'metadata' => $metadata,
            ])->save();
            $artwork->populate($item, null);

            if (in_array($kind, ['movie', 'episode'], true)) {
                FetchMediaCaptions::dispatch($item->id);
            }
        } catch (Throwable $exception) {
            Log::notice('Optional IPTV media enrichment failed safely.', [
                'media_item_id' => $item->id,
                'exception' => $exception::class,
            ]);
        }
    }
}
