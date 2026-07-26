<?php

namespace App\Jobs\Media;

use App\Models\MediaItem;
use App\Models\MediaSource;
use App\Models\User;
use App\Services\IntegrationSettings;
use App\Services\Media\ArtworkManager;
use App\Services\Media\MediaNameParser;
use App\Services\Media\MediaProbe;
use App\Services\Media\SourceMaterializer;
use App\Services\Media\Sources\MediaSourceRegistry;
use App\Services\Media\TmdbMetadataProvider;
use App\Services\Media\TranscodeStorage;
use App\Services\Media\TvmazeMetadataProvider;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class ScanMediaSource implements ShouldQueue
{
    use Queueable;

    public int $timeout = 3600;

    public int $tries = 3;

    public function __construct(public readonly string $sourceId) {}

    public function middleware(): array
    {
        return [(new WithoutOverlapping('media-source:'.$this->sourceId))->expireAfter(3700)];
    }

    public function handle(
        MediaSourceRegistry $registry,
        MediaProbe $probe,
        MediaNameParser $parser,
        TmdbMetadataProvider $metadata,
        ArtworkManager $artwork,
        ?TvmazeMetadataProvider $tvmaze = null,
        ?SourceMaterializer $materializer = null,
    ): void {
        $source = MediaSource::query()->find($this->sourceId);
        if (
            ! $source?->enabled
            || $source->type === MediaSource::TYPE_IPTV
        ) {
            return;
        }
        $source->update(['scan_status' => 'scanning', 'last_error_code' => null]);
        $adapter = $registry->for($source);
        $scanToken = (string) Str::ulid();
        $objectCount = 0;
        $maximumObjects = min(
            500000,
            max(1, (int) config('odissey.source_catalog_max_items', 100000)),
        );
        $captionJobsDispatched = 0;
        $maximumCaptionJobs = min(
            1000,
            max(
                0,
                (int) config(
                    'odissey.caption_auto_fetch_max_items_per_scan',
                    250,
                ),
            ),
        );

        try {
            $source->update(['capabilities' => $adapter->capabilities($source)]);
            $owner = User::query()->where('is_admin', true)->orderBy('id')->firstOrFail();
            $settings = app(IntegrationSettings::class);
            $hasCaptionProvider = $settings->has(
                'subdl_api_key',
                config('services.subdl.api_key'),
            ) || $settings->has(
                'opensubtitles_api_key',
                config('services.opensubtitles.api_key'),
            );
            $extensions = array_merge(config('odissey.video_extensions'), config('odissey.audio_extensions'));
            $materializer ??= new SourceMaterializer(
                $registry,
                app(TranscodeStorage::class),
            );
            foreach ($adapter->objects($source) as $object) {
                if (++$objectCount > $maximumObjects) {
                    throw new \RuntimeException('source_catalog_item_limit');
                }

                if (! in_array(strtolower(pathinfo($object->path, PATHINFO_EXTENSION)), $extensions, true)) {
                    continue;
                }
                $snapshot = null;
                $local = null;

                if ($source->type === MediaSource::TYPE_LOCAL) {
                    try {
                        $snapshot = $materializer->materializeObject(
                            $source,
                            $object->locator,
                            $object->size,
                            pathinfo($object->path, PATHINFO_EXTENSION),
                        );
                        $local = $snapshot['path'];
                    } catch (Throwable $exception) {
                        Log::notice('Local media snapshot failed safely.', [
                            'media_source_id' => $source->id,
                            'exception' => $exception::class,
                        ]);
                    }
                }

                try {
                    $stable = hash('sha256', $object->locator);
                    $technical = $probe->inspect($local, $object->path);
                    $parsed = $technical['media_kind'] === 'music'
                        ? ['kind' => 'music', 'title' => trim(preg_replace('/[._]+/', ' ', pathinfo(basename($object->path), PATHINFO_FILENAME)))]
                        : $parser->parse($object->path);
                    $tags = $technical['tags'] ?? [];
                    $enriched = $technical['media_kind'] === 'video' ? $metadata->match($parsed) : [];
                    if (($parsed['kind'] ?? '') === 'episode') {
                        $enriched = array_merge(($tvmaze ?? app(TvmazeMetadataProvider::class))->match($parsed), $enriched);
                    }
                    $item = DB::transaction(function () use ($source, $owner, $object, $stable, $scanToken, $technical, $parsed, $tags, $enriched): MediaItem {
                        return MediaItem::query()->updateOrCreate(
                            ['media_source_id' => $source->id, 'stable_id' => $stable],
                            [
                                'scan_token' => $scanToken,
                                'user_id' => $owner->id,
                                'title' => $tags['title'] ?? $enriched['title'] ?? $parsed['title'],
                                'media_kind' => $technical['media_kind'],
                                'source_type' => $source->type,
                                'source_locator' => $object->locator,
                                'relative_path' => $object->path,
                                'mime_type' => $technical['mime_type'],
                                'container' => $technical['container'],
                                'video_codec' => $technical['video_codec'] ?? null,
                                'audio_codec' => $technical['audio_codec'] ?? null,
                                'duration_ms' => $technical['duration_ms'] ?? null,
                                'requires_transcode' => $technical['requires_transcode'],
                                'size_bytes' => $object->size,
                                'source_modified_at' => $object->modifiedAt ? date('Y-m-d H:i:s', $object->modifiedAt) : null,
                                'missing_at' => null,
                                'metadata' => array_filter(array_merge($parsed, $enriched, [
                                    'artist' => $tags['artist'] ?? null, 'album' => $tags['album'] ?? null,
                                    'track' => $tags['track'] ?? null, 'technical' => $technical['technical'] ?? null,
                                ]), fn ($value) => $value !== null && $value !== ''),
                            ],
                        );
                    });
                    if ($item->media_kind === 'video') {
                        try {
                            $artwork->populate($item, $local);
                        } catch (Throwable $exception) {
                            Log::notice('Optional media artwork enrichment failed safely.', [
                                'media_item_id' => $item->id,
                                'exception' => $exception::class,
                            ]);
                        }
                    }
                    if (
                        $item->media_kind === 'video'
                        && $hasCaptionProvider
                        && $captionJobsDispatched < $maximumCaptionJobs
                    ) {
                        FetchMediaCaptions::dispatch($item->id);
                        $captionJobsDispatched++;
                    }
                } finally {
                    if (($snapshot['temporary'] ?? false) === true) {
                        File::delete($snapshot['path']);
                    }
                }
            }
            MediaItem::query()
                ->whereBelongsTo($source, 'source')
                ->where(function ($query) use ($scanToken): void {
                    $query->whereNull('scan_token')
                        ->orWhere('scan_token', '!=', $scanToken);
                })
                ->update(['missing_at' => now()]);
            $source->update(['scan_status' => 'ready', 'last_scanned_at' => now(), 'last_error_code' => null]);
        } catch (Throwable $e) {
            Log::warning('Media source scan failed.', ['source_id' => $source->id, 'exception' => $e::class]);
            $source->update(['scan_status' => 'failed', 'last_error_code' => 'source_scan_failed']);
        }
    }
}
