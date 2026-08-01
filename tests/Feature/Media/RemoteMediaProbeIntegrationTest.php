<?php

namespace Tests\Feature\Media;

use App\Jobs\Media\FinalizeMediaSourceScan;
use App\Jobs\Media\ProcessMediaSourceObject;
use App\Models\MediaItem;
use App\Models\MediaSource;
use App\Models\User;
use App\Services\Media\ArtworkManager;
use App\Services\Media\MediaProbe;
use App\Services\Media\MediaScanProgress;
use App\Services\Media\PlaybackDecision;
use App\Services\Media\RemoteMediaProbe;
use App\Services\Media\SourceMaterializer;
use App\Services\Media\TmdbMetadataProvider;
use App\Services\Media\TvmazeMetadataProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class RemoteMediaProbeIntegrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_remote_probe_budget_is_enforced_per_scan(): void
    {
        $source = MediaSource::query()->create([
            'name' => 'Budgeted remote source',
            'type' => MediaSource::TYPE_S3,
            'configuration' => ['synthetic' => true],
            'enabled' => true,
            'scan_status' => 'scanning',
            'active_scan_token' => '01J00000000000000000000000',
        ]);
        $progress = app(MediaScanProgress::class);

        $this->assertTrue($progress->reserveProbeJob(
            $source->id,
            $source->active_scan_token,
            1,
        ));
        $this->assertFalse($progress->reserveProbeJob(
            $source->id,
            $source->active_scan_token,
            1,
        ));
        $this->assertSame(1, $source->fresh()->scan_probe_jobs);
    }

    public function test_current_and_cooldown_items_do_not_consume_probe_budget_and_scan_completes(): void
    {
        Queue::fake();
        $owner = User::factory()->create([
            'is_admin' => true,
            'is_active' => true,
        ]);
        $scanToken = '01J00000000000000000000000';
        $source = MediaSource::query()->create([
            'name' => 'Current remote movies',
            'type' => MediaSource::TYPE_S3,
            'configuration' => ['synthetic' => true],
            'enabled' => true,
            'scan_status' => 'scanning',
            'active_scan_token' => $scanToken,
            'scan_discovery_complete' => true,
            'scan_discovered' => 2,
            'scan_processed' => 0,
        ]);
        $modifiedAt = 1710000000;
        $objects = [
            [
                'locator' => 'Movies/Current.mp4',
                'etag' => 'current-etag',
                'metadata' => [
                    'kind' => 'movie',
                    'source_etag' => 'current-etag',
                    'technical_probe_version' => RemoteMediaProbe::VERSION,
                ],
            ],
            [
                'locator' => 'Movies/Cooldown.mp4',
                'etag' => 'cooldown-etag',
                'metadata' => [
                    'kind' => 'movie',
                    'source_etag' => 'cooldown-etag',
                    'technical_probe_attempt_version' => RemoteMediaProbe::VERSION,
                    'technical_probe_attempted_at' => now()
                        ->utc()
                        ->toIso8601String(),
                ],
            ],
        ];

        foreach ($objects as $object) {
            MediaItem::query()->create([
                'user_id' => $owner->id,
                'media_source_id' => $source->id,
                'stable_id' => hash('sha256', $object['locator']),
                'scan_token' => 'old-scan',
                'title' => pathinfo(
                    basename($object['locator']),
                    PATHINFO_FILENAME,
                ),
                'media_kind' => 'video',
                'source_type' => MediaSource::TYPE_S3,
                'source_locator' => $object['locator'],
                'relative_path' => $object['locator'],
                'size_bytes' => 123456,
                'source_modified_at' => date(
                    'Y-m-d H:i:s',
                    $modifiedAt,
                ),
                'metadata' => $object['metadata'],
            ]);
        }

        $fallbackProbe = Mockery::mock(MediaProbe::class);
        $fallbackProbe->shouldNotReceive('inspect');
        $materializer = Mockery::mock(SourceMaterializer::class);
        $materializer->shouldNotReceive('materializeObject');
        $artwork = Mockery::mock(ArtworkManager::class);
        $artwork->shouldNotReceive('populate');
        foreach ($objects as $object) {
            $job = new ProcessMediaSourceObject(
                $source->id,
                $scanToken,
                $object['locator'],
                $object['locator'],
                123456,
                $object['etag'],
                $modifiedAt,
            );
            app()->call([$job, 'handle'], [
                'probe' => $fallbackProbe,
                'artwork' => $artwork,
                'materializer' => $materializer,
            ]);
        }

        $source->refresh();
        $this->assertSame(0, $source->scan_probe_jobs);
        $this->assertSame(2, $source->scan_processed);
        $this->assertSame(0, $source->scan_failed);
        Queue::assertPushed(FinalizeMediaSourceScan::class, 1);
        foreach (Queue::pushed(FinalizeMediaSourceScan::class) as $finalizer) {
            app()->call([$finalizer, 'handle']);
        }

        $this->assertSame('ready', $source->refresh()->scan_status);
        $this->assertNull($source->last_error_code);
    }

    public function test_exhausted_probe_budget_falls_back_and_scan_completes(): void
    {
        Queue::fake();
        config(['odissey.remote_probe_max_items_per_scan' => 0]);
        User::factory()->create([
            'is_admin' => true,
            'is_active' => true,
        ]);
        $scanToken = '01J00000000000000000000000';
        $source = MediaSource::query()->create([
            'name' => 'Deferred remote movies',
            'type' => MediaSource::TYPE_S3,
            'configuration' => ['synthetic' => true],
            'enabled' => true,
            'scan_status' => 'scanning',
            'active_scan_token' => $scanToken,
            'scan_discovery_complete' => true,
            'scan_discovered' => 1,
            'scan_processed' => 0,
        ]);
        $remoteProbe = Mockery::mock(RemoteMediaProbe::class);
        $remoteProbe->shouldNotReceive('shouldAttempt');
        $remoteProbe->shouldNotReceive('inspect');
        $fallbackProbe = Mockery::mock(MediaProbe::class);
        $fallbackProbe->shouldReceive('inspect')
            ->once()
            ->with(null, 'Movies/Deferred.mp4')
            ->andReturn($this->technicalResult());
        $materializer = Mockery::mock(SourceMaterializer::class);
        $materializer->shouldNotReceive('materializeObject');
        $artwork = Mockery::mock(ArtworkManager::class);
        $artwork->shouldReceive('populate')->once();
        $tmdb = Mockery::mock(TmdbMetadataProvider::class);
        $tmdb->shouldReceive('match')->once()->andReturn([]);
        $tvmaze = Mockery::mock(TvmazeMetadataProvider::class);
        $tvmaze->shouldNotReceive('match');
        $job = new ProcessMediaSourceObject(
            $source->id,
            $scanToken,
            'Movies/Deferred.mp4',
            'Movies/Deferred.mp4',
            123456,
            'synthetic-etag',
            1710000000,
        );

        app()->call([$job, 'handle'], [
            'probe' => $fallbackProbe,
            'metadata' => $tmdb,
            'artwork' => $artwork,
            'materializer' => $materializer,
            'tvmaze' => $tvmaze,
            'remoteProbe' => $remoteProbe,
        ]);

        $item = MediaItem::query()->sole();
        $this->assertSame('h264', $item->video_codec);
        $this->assertArrayNotHasKey(
            'technical_probe_version',
            $item->metadata,
        );
        $source->refresh();
        $this->assertSame(0, $source->scan_probe_jobs);
        $this->assertSame(1, $source->scan_processed);
        $this->assertSame(0, $source->scan_failed);
        Queue::assertPushed(FinalizeMediaSourceScan::class, 1);
        foreach (Queue::pushed(FinalizeMediaSourceScan::class) as $finalizer) {
            app()->call([$finalizer, 'handle']);
        }

        $this->assertSame('ready', $source->refresh()->scan_status);
        $this->assertNull($source->last_error_code);
    }

    #[DataProvider('remoteLibraryTypes')]
    public function test_remote_library_scan_persists_probe_data_for_native_direct_play(
        string $sourceType,
    ): void {
        Queue::fake();
        User::factory()->create(['is_admin' => true, 'is_active' => true]);
        $scanToken = '01J00000000000000000000000';
        $source = MediaSource::query()->create([
            'name' => 'Remote movies',
            'type' => $sourceType,
            'configuration' => ['synthetic' => true],
            'capabilities' => [
                'range' => true,
                'seekable' => true,
                'read_only' => true,
            ],
            'enabled' => true,
            'scan_status' => 'scanning',
            'active_scan_token' => $scanToken,
            'scan_discovery_complete' => true,
            'scan_discovered' => 1,
            'scan_processed' => 0,
        ]);
        $technical = $this->technicalResult();
        $remoteProbe = Mockery::mock(RemoteMediaProbe::class);
        $remoteProbe->shouldReceive('inspect')
            ->once()
            ->withArgs(fn (
                MediaSource $candidate,
                string $locator,
                string $path,
            ): bool => $candidate->is($source)
                && $locator === 'Movies/Compatible.mp4'
                && $path === 'Movies/Compatible.mp4')
            ->andReturn($technical);
        $fallbackProbe = Mockery::mock(MediaProbe::class);
        $fallbackProbe->shouldNotReceive('inspect');
        $materializer = Mockery::mock(SourceMaterializer::class);
        $materializer->shouldNotReceive('materializeObject');
        $artwork = Mockery::mock(ArtworkManager::class);
        $artwork->shouldReceive('populate')->once();
        $tmdb = Mockery::mock(TmdbMetadataProvider::class);
        $tmdb->shouldReceive('match')->once()->andReturn([]);
        $tvmaze = Mockery::mock(TvmazeMetadataProvider::class);
        $tvmaze->shouldNotReceive('match');
        $job = new ProcessMediaSourceObject(
            $source->id,
            $scanToken,
            'Movies/Compatible.mp4',
            'Movies/Compatible.mp4',
            123456,
            'synthetic-etag',
            1710000000,
        );

        app()->call([$job, 'handle'], [
            'probe' => $fallbackProbe,
            'metadata' => $tmdb,
            'artwork' => $artwork,
            'materializer' => $materializer,
            'tvmaze' => $tvmaze,
            'remoteProbe' => $remoteProbe,
        ]);

        $item = MediaItem::query()->sole();
        $this->assertSame('h264', $item->video_codec);
        $this->assertSame('aac', $item->audio_codec);
        $this->assertSame(120000, $item->duration_ms);
        $this->assertSame(
            RemoteMediaProbe::VERSION,
            $item->metadata['technical_probe_version'],
        );
        $this->assertSame(1920, $item->metadata['technical']['width']);
        $this->assertSame(
            'direct',
            (new PlaybackDecision)->forNative(
                $item,
                $this->nativeCapabilities(),
            )['mode'],
        );
    }

    public function test_failed_backfill_probe_preserves_existing_technical_data(): void
    {
        Queue::fake();
        $admin = User::factory()->create([
            'is_admin' => true,
            'is_active' => true,
        ]);
        $scanToken = '01J00000000000000000000000';
        $source = MediaSource::query()->create([
            'name' => 'Remote movies',
            'type' => MediaSource::TYPE_S3,
            'configuration' => ['synthetic' => true],
            'enabled' => true,
            'scan_status' => 'scanning',
            'active_scan_token' => $scanToken,
            'scan_discovery_complete' => true,
            'scan_discovered' => 1,
        ]);
        $modifiedAt = 1710000000;
        $item = MediaItem::query()->create([
            'user_id' => $admin->id,
            'media_source_id' => $source->id,
            'stable_id' => hash('sha256', 'Movies/Compatible.mp4'),
            'scan_token' => 'old-scan',
            'title' => 'Compatible',
            'media_kind' => 'video',
            'source_type' => MediaSource::TYPE_S3,
            'source_locator' => 'Movies/Compatible.mp4',
            'relative_path' => 'Movies/Compatible.mp4',
            'mime_type' => 'video/mp4',
            'container' => 'mp4',
            'video_codec' => 'hevc',
            'audio_codec' => 'eac3',
            'duration_ms' => 180000,
            'requires_transcode' => true,
            'size_bytes' => 123456,
            'source_modified_at' => date('Y-m-d H:i:s', $modifiedAt),
            'metadata' => [
                'kind' => 'movie',
                'source_etag' => 'synthetic-etag',
                'technical' => [
                    'width' => 3840,
                    'height' => 2160,
                    'frame_rate' => 24,
                ],
            ],
        ]);
        $remoteProbe = Mockery::mock(RemoteMediaProbe::class);
        $remoteProbe->shouldReceive('shouldAttempt')
            ->once()
            ->withArgs(fn (MediaItem $candidate): bool => $candidate->is($item))
            ->andReturnTrue();
        $remoteProbe->shouldReceive('inspect')->once()->andReturnNull();
        $fallbackProbe = Mockery::mock(MediaProbe::class);
        $fallbackProbe->shouldNotReceive('inspect');
        $materializer = Mockery::mock(SourceMaterializer::class);
        $materializer->shouldNotReceive('materializeObject');
        $artwork = Mockery::mock(ArtworkManager::class);
        $artwork->shouldNotReceive('populate');
        $job = new ProcessMediaSourceObject(
            $source->id,
            $scanToken,
            'Movies/Compatible.mp4',
            'Movies/Compatible.mp4',
            123456,
            'synthetic-etag',
            $modifiedAt,
        );

        app()->call([$job, 'handle'], [
            'probe' => $fallbackProbe,
            'artwork' => $artwork,
            'materializer' => $materializer,
            'remoteProbe' => $remoteProbe,
        ]);

        $item->refresh();
        $this->assertSame('hevc', $item->video_codec);
        $this->assertSame('eac3', $item->audio_codec);
        $this->assertSame(3840, $item->metadata['technical']['width']);
        $this->assertSame($scanToken, $item->scan_token);
        $this->assertArrayNotHasKey(
            'technical_probe_version',
            $item->metadata,
        );
        $this->assertSame(
            RemoteMediaProbe::VERSION,
            $item->metadata['technical_probe_attempt_version'],
        );
        $this->assertNotEmpty(
            $item->metadata['technical_probe_attempted_at'],
        );
    }

    /** @return iterable<string, array{string}> */
    public static function remoteLibraryTypes(): iterable
    {
        yield 'S3' => [MediaSource::TYPE_S3];
        yield 'WebDAV' => [MediaSource::TYPE_WEBDAV];
    }

    /** @return array<string, mixed> */
    private function technicalResult(): array
    {
        return [
            'media_kind' => 'video',
            'mime_type' => 'video/mp4',
            'container' => 'mp4',
            'video_codec' => 'h264',
            'audio_codec' => 'aac',
            'duration_ms' => 120000,
            'requires_transcode' => false,
            'technical' => [
                'width' => 1920,
                'height' => 1080,
                'frame_rate' => 24.0,
                'bit_rate' => '6500000',
                'video_profile' => 'Main',
                'video_level' => 41,
                'bit_depth' => 8,
                'dynamic_range' => 'sdr',
            ],
            'tags' => [],
        ];
    }

    /** @return array<string, mixed> */
    private function nativeCapabilities(): array
    {
        return [
            'videoCodecs' => ['h264', 'hevc'],
            'audioCodecs' => ['aac', 'ac3', 'eac3'],
            'dynamicRanges' => ['sdr', 'hdr10', 'hlg'],
            'maximumWidth' => 3840,
            'maximumHeight' => 2160,
            'maximumFrameRate' => 60,
            'subtitleFormats' => ['webvtt'],
        ];
    }
}
