<?php

namespace App\Jobs\Media;

use App\Models\MediaItem;
use App\Models\MediaSource;
use App\Models\User;
use App\Services\IntegrationSettings;
use App\Services\Media\ArtworkManager;
use App\Services\Media\MediaNameParser;
use App\Services\Media\MediaProbe;
use App\Services\Media\Sources\MediaSourceRegistry;
use App\Services\Media\TmdbMetadataProvider;
use App\Services\Media\TvmazeMetadataProvider;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
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

    public function handle(MediaSourceRegistry $registry, MediaProbe $probe, MediaNameParser $parser, TmdbMetadataProvider $metadata, ArtworkManager $artwork, ?TvmazeMetadataProvider $tvmaze = null): void
    {
        $source = MediaSource::query()->find($this->sourceId);
        if (! $source?->enabled) {
            return;
        }
        $source->update(['scan_status' => 'scanning', 'last_error_code' => null]);
        $adapter = $registry->for($source);
        $seen = [];
        try {
            $source->update(['capabilities' => $adapter->capabilities($source)]);
            $owner = User::query()->where('is_admin', true)->orderBy('id')->firstOrFail();
            $extensions = array_merge(config('odissey.video_extensions'), config('odissey.audio_extensions'));
            foreach ($adapter->objects($source) as $object) {
                if (! in_array(strtolower(pathinfo($object->path, PATHINFO_EXTENSION)), $extensions, true)) {
                    continue;
                }
                $stable = hash('sha256', $object->locator);
                $seen[] = $stable;
                $local = $adapter->localPath($source, $object->locator);
                $technical = $probe->inspect($local, $object->path);
                $parsed = $technical['media_kind'] === 'music'
                    ? ['kind' => 'music', 'title' => trim(preg_replace('/[._]+/', ' ', pathinfo(basename($object->path), PATHINFO_FILENAME)))]
                    : $parser->parse($object->path);
                $tags = $technical['tags'] ?? [];
                $enriched = $technical['media_kind'] === 'video' ? $metadata->match($parsed) : [];
                if (($parsed['kind'] ?? '') === 'episode') {
                    $enriched = array_merge(($tvmaze ?? app(TvmazeMetadataProvider::class))->match($parsed), $enriched);
                }
                $item = DB::transaction(function () use ($source, $owner, $object, $stable, $technical, $parsed, $tags, $enriched): MediaItem {
                    return MediaItem::query()->updateOrCreate(
                        ['media_source_id' => $source->id, 'stable_id' => $stable],
                        [
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
                    $artwork->populate($item, $local);
                }
                $settings = app(IntegrationSettings::class);
                if ($item->media_kind === 'video' && ($settings->has('subdl_api_key', config('services.subdl.api_key')) || $settings->has('opensubtitles_api_key', config('services.opensubtitles.api_key')))) {
                    FetchMediaCaptions::dispatch($item->id);
                }
            }
            MediaItem::query()->whereBelongsTo($source, 'source')->when($seen, fn ($q) => $q->whereNotIn('stable_id', $seen))->update(['missing_at' => now()]);
            $source->update(['scan_status' => 'ready', 'last_scanned_at' => now(), 'last_error_code' => null]);
        } catch (Throwable $e) {
            Log::warning('Media source scan failed.', ['source_id' => $source->id, 'exception' => $e::class]);
            $source->update(['scan_status' => 'failed', 'last_error_code' => 'source_scan_failed']);
        }
    }
}
