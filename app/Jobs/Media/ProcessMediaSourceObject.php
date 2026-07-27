<?php

namespace App\Jobs\Media;

use App\Models\MediaItem;
use App\Models\MediaSource;
use App\Models\User;
use App\Services\IntegrationSettings;
use App\Services\Media\ArtworkManager;
use App\Services\Media\MediaNameParser;
use App\Services\Media\MediaProbe;
use App\Services\Media\MediaScanProgress;
use App\Services\Media\SourceMaterializer;
use App\Services\Media\TmdbMetadataProvider;
use App\Services\Media\TvmazeMetadataProvider;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Throwable;

class ProcessMediaSourceObject implements ShouldQueue
{
    use Queueable;

    public int $timeout = 900;

    public int $tries = 2;

    public function __construct(
        public readonly string $sourceId,
        public readonly string $scanToken,
        public readonly string $locator,
        public readonly string $path,
        public readonly int $size,
        public readonly ?string $etag,
        public readonly ?int $modifiedAt,
    ) {
        $this->onQueue('media-scan');
    }

    public function handle(
        MediaProbe $probe,
        MediaNameParser $parser,
        TmdbMetadataProvider $metadata,
        ArtworkManager $artwork,
        SourceMaterializer $materializer,
        TvmazeMetadataProvider $tvmaze,
        MediaScanProgress $progress,
    ): void {
        $source = MediaSource::query()->find($this->sourceId);
        $participates = $source?->active_scan_token === $this->scanToken;
        $failed = false;

        try {
            if (! $participates || ! $source->enabled) {
                return;
            }

            $owner = User::query()
                ->where('is_admin', true)
                ->orderBy('id')
                ->firstOrFail();
            $stable = hash('sha256', $this->locator);
            $sourceModifiedAt = $this->modifiedAt === null
                ? null
                : date('Y-m-d H:i:s', $this->modifiedAt);
            $existing = MediaItem::query()
                ->whereBelongsTo($source, 'source')
                ->where('stable_id', $stable)
                ->first();

            if ($this->unchanged($existing, $sourceModifiedAt)) {
                $existing->forceFill([
                    'scan_token' => $this->scanToken,
                    'missing_at' => null,
                ])->save();

                return;
            }

            $snapshot = null;
            $local = null;
            if ($source->type === MediaSource::TYPE_LOCAL) {
                $snapshot = $materializer->materializeObject(
                    $source,
                    $this->locator,
                    $this->size,
                    pathinfo($this->path, PATHINFO_EXTENSION),
                );
                $local = $snapshot['path'];
            }

            try {
                $technical = $probe->inspect($local, $this->path);
                $parsed = $technical['media_kind'] === 'music'
                    ? [
                        'kind' => 'music',
                        'title' => trim(preg_replace(
                            '/[._]+/',
                            ' ',
                            pathinfo(
                                basename($this->path),
                                PATHINFO_FILENAME,
                            ),
                        )),
                    ]
                    : $parser->parse($this->path);
                $tags = $technical['tags'] ?? [];
                $enriched = $technical['media_kind'] === 'video'
                    ? $metadata->match($parsed)
                    : [];
                if (($parsed['kind'] ?? '') === 'episode') {
                    $enriched = array_merge(
                        $tvmaze->match($parsed),
                        $enriched,
                    );
                }

                $item = DB::transaction(function () use (
                    $source,
                    $owner,
                    $stable,
                    $sourceModifiedAt,
                    $technical,
                    $parsed,
                    $tags,
                    $enriched,
                ): MediaItem {
                    return MediaItem::query()->updateOrCreate(
                        [
                            'media_source_id' => $source->id,
                            'stable_id' => $stable,
                        ],
                        [
                            'scan_token' => $this->scanToken,
                            'user_id' => $owner->id,
                            'title' => $tags['title']
                                ?? $enriched['title']
                                ?? $parsed['title'],
                            'media_kind' => $technical['media_kind'],
                            'source_type' => $source->type,
                            'source_locator' => $this->locator,
                            'relative_path' => $this->path,
                            'mime_type' => $technical['mime_type'],
                            'container' => $technical['container'],
                            'video_codec' => $technical['video_codec'] ?? null,
                            'audio_codec' => $technical['audio_codec'] ?? null,
                            'duration_ms' => $technical['duration_ms'] ?? null,
                            'requires_transcode' => $technical['requires_transcode'],
                            'size_bytes' => $this->size,
                            'source_modified_at' => $sourceModifiedAt,
                            'missing_at' => null,
                            'metadata' => array_filter(array_merge(
                                $parsed,
                                $enriched,
                                [
                                    'artist' => $tags['artist'] ?? null,
                                    'album' => $tags['album'] ?? null,
                                    'track' => $tags['track'] ?? null,
                                    'technical' => $technical['technical'] ?? null,
                                    'source_etag' => $this->etag,
                                ],
                            ), fn (mixed $value): bool => $value !== null
                                && $value !== ''),
                        ],
                    );
                });

                if ($item->media_kind === 'video') {
                    try {
                        $artwork->populate($item, $local);
                    } catch (Throwable $exception) {
                        Log::notice(
                            'Optional media artwork enrichment failed safely.',
                            [
                                'media_item_id' => $item->id,
                                'exception' => $exception::class,
                            ],
                        );
                    }
                }

                if (
                    $item->media_kind === 'video'
                    && $this->hasCaptionProvider()
                    && $progress->reserveCaptionJob(
                        $source->id,
                        $this->scanToken,
                        min(
                            1000,
                            max(0, (int) config(
                                'odissey.caption_auto_fetch_max_items_per_scan',
                                250,
                            )),
                        ),
                    )
                ) {
                    FetchMediaCaptions::dispatch($item->id);
                }
            } finally {
                if (($snapshot['temporary'] ?? false) === true) {
                    File::delete($snapshot['path']);
                }
            }
        } catch (Throwable $exception) {
            $failed = true;
            Log::warning('Media object scan failed safely.', [
                'media_source_id' => $this->sourceId,
                'exception' => $exception::class,
            ]);
        } finally {
            if ($participates) {
                $progress->completeObject(
                    $this->sourceId,
                    $this->scanToken,
                    $failed,
                );
            }
        }
    }

    private function unchanged(
        ?MediaItem $item,
        ?string $sourceModifiedAt,
    ): bool {
        if ($item === null || (int) $item->size_bytes !== $this->size) {
            return false;
        }
        $storedModifiedAt = $item->source_modified_at?->format(
            'Y-m-d H:i:s',
        );
        if ($storedModifiedAt !== $sourceModifiedAt) {
            return false;
        }

        return $this->etag === null
            || ($item->metadata['source_etag'] ?? null) === $this->etag;
    }

    private function hasCaptionProvider(): bool
    {
        $settings = app(IntegrationSettings::class);

        return $settings->has(
            'subdl_api_key',
            config('services.subdl.api_key'),
        ) || $settings->has(
            'opensubtitles_api_key',
            config('services.opensubtitles.api_key'),
        );
    }

    public function failed(?Throwable $exception): void
    {
        app(MediaScanProgress::class)->completeObject(
            $this->sourceId,
            $this->scanToken,
            true,
        );
    }
}
