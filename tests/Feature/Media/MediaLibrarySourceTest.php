<?php

namespace Tests\Feature\Media;

use App\Jobs\Media\FetchMediaCaptions;
use App\Jobs\Media\FinalizeMediaSourceScan;
use App\Jobs\Media\ProcessMediaSourceObject;
use App\Jobs\Media\ScanMediaSource;
use App\Models\MediaFavorite;
use App\Models\MediaItem;
use App\Models\MediaSource;
use App\Models\User;
use App\Services\Media\ArtworkManager;
use App\Services\Media\MediaProbe;
use App\Services\Media\MediaScanDispatcher;
use App\Services\Media\SourceMaterializer;
use App\Services\Media\Sources\LocalSourceAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class MediaLibrarySourceTest extends TestCase
{
    use RefreshDatabase;

    private string $root;

    private string $transcodeRoot;

    protected function setUp(): void
    {
        parent::setUp();
        if (! config('app.key')) {
            config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        }
        $this->root = sys_get_temp_dir().'/odissey-library-'.bin2hex(random_bytes(5));
        $this->transcodeRoot = $this->root.'-transcodes';
        File::ensureDirectoryExists($this->root);
        config([
            'odissey.artwork_path' => $this->root.'/.artwork',
            'odissey.local_source_roots' => [$this->root],
            'odissey.transcode_min_free_bytes' => 0,
            'odissey.transcode_path' => $this->transcodeRoot,
            'services.tmdb.token' => null,
        ]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->root);
        File::deleteDirectory($this->transcodeRoot);
        parent::tearDown();
    }

    public function test_admin_can_validate_a_read_only_source_and_queue_scan(): void
    {
        Queue::fake();
        $admin = User::factory()->create(['is_admin' => true, 'is_active' => true]);
        $this->actingAs($admin)->post(route('media.admin.sources.store'), [
            'name' => 'Movies', 'type' => 'local', 'path' => $this->root,
        ])->assertRedirect(route('media.admin.sources.index'));

        $source = MediaSource::firstOrFail();
        $this->assertSame(['range' => true, 'seekable' => true, 'read_only' => true], $source->capabilities);
        $this->assertStringNotContainsString($this->root, $source->getRawOriginal('configuration'));
        Queue::assertPushed(ScanMediaSource::class);
    }

    public function test_scans_are_stable_preserve_favorites_and_mark_missing_items(): void
    {
        $admin = User::factory()->create(['is_admin' => true, 'is_active' => true]);
        $viewer = User::factory()->create();
        File::put($this->root.'/Song.One.mp3', 'synthetic audio');
        $source = MediaSource::create(['name' => 'Music', 'type' => 'local', 'configuration' => ['path' => $this->root]]);
        $this->scan($source);
        $item = MediaItem::firstOrFail();
        $this->assertSame('music', $item->media_kind);
        MediaFavorite::create(['user_id' => $viewer->id, 'media_item_id' => $item->id]);

        $this->scan($source);
        $this->assertSame($item->id, MediaItem::firstOrFail()->id);
        $this->assertDatabaseHas('media_favorites', ['user_id' => $viewer->id, 'media_item_id' => $item->id]);
        $this->actingAs($viewer)->get(route('media.index', ['kind' => 'music']))->assertOk()->assertSee('Song One');

        File::delete($this->root.'/Song.One.mp3');
        $this->scan($source);
        $this->assertNotNull($item->fresh()->missing_at);
    }

    public function test_discovery_fans_media_objects_out_to_the_bounded_scan_queue(): void
    {
        Queue::fake();
        $admin = User::factory()->create([
            'is_admin' => true,
            'is_active' => true,
        ]);
        File::put($this->root.'/Movie.One.mp4', 'synthetic video one');
        File::put($this->root.'/Movie.Two.mp4', 'synthetic video two');
        $source = MediaSource::create([
            'name' => 'Parallel movies',
            'type' => 'local',
            'configuration' => ['path' => $this->root],
        ]);

        $this->runDiscovery($source);

        $source->refresh();
        $this->assertSame('scanning', $source->scan_status);
        $this->assertTrue($source->scan_discovery_complete);
        $this->assertSame(2, $source->scan_discovered);
        $this->assertSame(0, $source->scan_processed);
        Queue::assertPushed(
            ProcessMediaSourceObject::class,
            2,
        );
        Queue::assertPushed(
            ProcessMediaSourceObject::class,
            fn (ProcessMediaSourceObject $job, string $queue): bool => $queue === 'media-scan',
        );
        $this->actingAs($admin)
            ->get(route('media.admin.sources.index'))
            ->assertOk()
            ->assertSee('2 discovered')
            ->assertSee('hx-trigger="every 3s"', escape: false);
    }

    public function test_unchanged_media_skips_probe_and_artwork_work_on_repeat_scans(): void
    {
        User::factory()->create(['is_admin' => true, 'is_active' => true]);
        File::put($this->root.'/Movie.One.mp4', 'synthetic video');
        $source = MediaSource::create([
            'name' => 'Incremental movies',
            'type' => 'local',
            'configuration' => ['path' => $this->root],
        ]);
        $this->scan($source);

        Queue::fake();
        $this->runDiscovery($source);
        $probe = Mockery::mock(MediaProbe::class);
        $probe->shouldNotReceive('inspect');
        $artwork = Mockery::mock(ArtworkManager::class);
        $artwork->shouldNotReceive('populate');

        foreach (Queue::pushed(ProcessMediaSourceObject::class) as $job) {
            app()->call(
                [$job, 'handle'],
                ['probe' => $probe, 'artwork' => $artwork],
            );
        }
        foreach (Queue::pushed(FinalizeMediaSourceScan::class) as $job) {
            app()->call([$job, 'handle']);
        }

        $source->refresh();
        $this->assertSame('ready', $source->scan_status);
        $this->assertSame(1, $source->scan_processed);
        $this->assertNull($source->last_error_code);
    }

    public function test_one_media_failure_does_not_stall_parallel_scan_finalization(): void
    {
        Queue::fake();
        User::factory()->create(['is_admin' => true, 'is_active' => true]);
        File::put($this->root.'/Movie.One.mp4', 'synthetic video');
        $source = MediaSource::create([
            'name' => 'Recoverable scan',
            'type' => 'local',
            'configuration' => ['path' => $this->root],
        ]);
        $this->runDiscovery($source);
        $materializer = Mockery::mock(SourceMaterializer::class);
        $materializer->shouldReceive('materializeObject')
            ->once()
            ->andThrow(new RuntimeException('synthetic scan failure'));

        foreach (Queue::pushed(ProcessMediaSourceObject::class) as $job) {
            app()->call(
                [$job, 'handle'],
                ['materializer' => $materializer],
            );
        }
        foreach (Queue::pushed(FinalizeMediaSourceScan::class) as $job) {
            app()->call([$job, 'handle']);
        }

        $source->refresh();
        $this->assertSame('ready', $source->scan_status);
        $this->assertSame(1, $source->scan_processed);
        $this->assertSame(1, $source->scan_failed);
        $this->assertSame(
            'source_scan_partial_failure',
            $source->last_error_code,
        );
    }

    public function test_interrupted_scan_is_requeued_with_a_new_claim_token(): void
    {
        Queue::fake();
        User::factory()->create([
            'is_admin' => true,
            'is_active' => true,
        ]);
        $source = MediaSource::create([
            'name' => 'Interrupted scan',
            'type' => 'local',
            'configuration' => ['path' => $this->root],
            'scan_status' => 'scanning',
            'active_scan_token' => '01J00000000000000000000000',
        ]);
        $oldToken = $source->active_scan_token;

        $this->artisan(
            'media:sources:scan',
            ['--recover-interrupted' => true],
        )->expectsOutput('Queued 1 media source scan(s).')
            ->assertSuccessful();

        $source->refresh();
        $this->assertSame('queued', $source->scan_status);
        $this->assertNotSame($oldToken, $source->active_scan_token);
        Queue::assertPushed(
            ScanMediaSource::class,
            fn (ScanMediaSource $job): bool => (
                $job->sourceId === $source->id
                && $job->scanToken === $source->active_scan_token
            ),
        );

        app()->call([
            new ScanMediaSource($source->id, $oldToken),
            'handle',
        ]);

        $this->assertSame('queued', $source->refresh()->scan_status);
        Queue::assertNotPushed(ProcessMediaSourceObject::class);
    }

    public function test_local_adapter_rejects_symlink_escape(): void
    {
        $outside = tempnam(sys_get_temp_dir(), 'outside-');
        symlink($outside, $this->root.'/escape.mp4');
        $source = MediaSource::create(['name' => 'Safe', 'type' => 'local', 'configuration' => ['path' => $this->root]]);
        $objects = iterator_to_array(app(LocalSourceAdapter::class)->objects($source));
        $this->assertCount(0, $objects);
        File::delete($outside);
    }

    public function test_local_media_is_snapshotted_from_a_validated_handle_before_parser_use(): void
    {
        $path = $this->root.'/Movie.One.mp4';
        File::put($path, 'validated media bytes');
        $source = MediaSource::create([
            'name' => 'Safe snapshot',
            'type' => 'local',
            'configuration' => ['path' => $this->root],
        ]);
        $adapter = app(LocalSourceAdapter::class);

        $this->assertNull($adapter->localPath($source, 'Movie.One.mp4'));

        $snapshot = app(SourceMaterializer::class)->materializeObject(
            $source,
            'Movie.One.mp4',
            File::size($path),
            'mp4',
        );

        try {
            $this->assertTrue($snapshot['temporary']);
            $this->assertNotSame($path, $snapshot['path']);
            $this->assertSame('validated media bytes', File::get($snapshot['path']));
            $this->assertSame(0600, fileperms($snapshot['path']) & 0777);
        } finally {
            File::delete($snapshot['path']);
        }
    }

    public function test_optional_artwork_failures_do_not_abort_the_catalog_scan(): void
    {
        User::factory()->create(['is_admin' => true, 'is_active' => true]);
        File::put($this->root.'/Movie.One.mp4', 'synthetic video');
        File::put($this->root.'/Movie.Two.mp4', 'synthetic video');
        $source = MediaSource::create([
            'name' => 'Movies',
            'type' => 'local',
            'configuration' => ['path' => $this->root],
        ]);
        $artwork = Mockery::mock(ArtworkManager::class);
        $artwork->shouldReceive('populate')
            ->twice()
            ->andThrow(new RuntimeException('synthetic quota failure'));

        $this->scan($source, $artwork);

        $this->assertSame(2, MediaItem::query()->count());
        $this->assertSame('ready', $source->refresh()->scan_status);
    }

    public function test_automatic_caption_fetch_fanout_is_capped_per_scan(): void
    {
        Queue::fake();
        config([
            'odissey.caption_auto_fetch_max_items_per_scan' => 2,
            'services.subdl.api_key' => 'synthetic-key',
        ]);
        User::factory()->create(['is_admin' => true, 'is_active' => true]);
        foreach (range(1, 4) as $index) {
            File::put(
                $this->root.'/Movie.'.$index.'.mp4',
                'synthetic video '.$index,
            );
        }
        $source = MediaSource::create([
            'name' => 'Movies',
            'type' => 'local',
            'configuration' => ['path' => $this->root],
        ]);
        $artwork = Mockery::mock(ArtworkManager::class);
        $artwork->shouldReceive('populate')->times(4);

        $this->scan($source, $artwork);

        Queue::assertPushed(FetchMediaCaptions::class, 2);
        $this->assertSame(4, MediaItem::query()->count());
        $this->assertSame('ready', $source->refresh()->scan_status);
    }

    private function scan(
        MediaSource $source,
        ?ArtworkManager $artwork = null,
    ): void {
        Queue::fake();
        $this->runDiscovery($source);
        $jobs = Queue::pushed(ProcessMediaSourceObject::class);
        foreach ($jobs as $job) {
            app()->call(
                [$job, 'handle'],
                $artwork === null ? [] : ['artwork' => $artwork],
            );
        }
        foreach (Queue::pushed(FinalizeMediaSourceScan::class) as $job) {
            app()->call([$job, 'handle']);
        }
    }

    private function runDiscovery(MediaSource $source): void
    {
        $this->assertTrue(app(MediaScanDispatcher::class)->queue($source));
        $job = Queue::pushed(ScanMediaSource::class)->last();
        $this->assertInstanceOf(ScanMediaSource::class, $job);
        app()->call([$job, 'handle']);
    }
}
