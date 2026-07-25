<?php

namespace Tests\Feature\Media;

use App\Jobs\Media\ScanMediaSource;
use App\Models\MediaFavorite;
use App\Models\MediaItem;
use App\Models\MediaSource;
use App\Models\User;
use App\Services\Media\ArtworkManager;
use App\Services\Media\MediaNameParser;
use App\Services\Media\MediaProbe;
use App\Services\Media\Sources\LocalSourceAdapter;
use App\Services\Media\Sources\MediaSourceRegistry;
use App\Services\Media\TmdbMetadataProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class MediaLibrarySourceTest extends TestCase
{
    use RefreshDatabase;

    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        if (! config('app.key')) {
            config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        }
        $this->root = sys_get_temp_dir().'/odissey-library-'.bin2hex(random_bytes(5));
        File::ensureDirectoryExists($this->root);
        config(['odissey.local_source_roots' => [$this->root], 'services.tmdb.token' => null, 'odissey.artwork_path' => $this->root.'/.artwork']);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->root);
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

    public function test_local_adapter_rejects_symlink_escape(): void
    {
        $outside = tempnam(sys_get_temp_dir(), 'outside-');
        symlink($outside, $this->root.'/escape.mp4');
        $source = MediaSource::create(['name' => 'Safe', 'type' => 'local', 'configuration' => ['path' => $this->root]]);
        $objects = iterator_to_array(app(LocalSourceAdapter::class)->objects($source));
        $this->assertCount(0, $objects);
        File::delete($outside);
    }

    private function scan(MediaSource $source): void
    {
        (new ScanMediaSource($source->id))->handle(
            app(MediaSourceRegistry::class), app(MediaProbe::class), app(MediaNameParser::class),
            app(TmdbMetadataProvider::class), app(ArtworkManager::class),
        );
    }
}
