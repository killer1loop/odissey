<?php

namespace Tests\Feature\Media;

use App\Models\MediaItem;
use App\Models\User;
use App\Services\Media\ArtworkManager;
use App\Services\Media\BoundedMediaDownloader;
use App\Services\Media\MediaAssetStorage;
use App\Services\Media\MediaProcessFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

class MediaArtworkFallbackTest extends TestCase
{
    use RefreshDatabase;

    public function test_items_without_artwork_do_not_create_empty_directories(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $item = MediaItem::query()->create([
            'user_id' => $user->id,
            'title' => 'Episode without artwork',
            'media_kind' => 'video',
            'source_type' => 'iptv',
            'source_locator' => json_encode([
                'type' => 'episode',
                'id' => '601',
                'extension' => 'mp4',
            ], JSON_THROW_ON_ERROR),
            'metadata' => ['kind' => 'episode'],
        ]);
        $root = storage_path('framework/testing-artwork-'.Str::ulid());
        config(['odissey.artwork_path' => $root]);

        try {
            app(ArtworkManager::class)->populate($item, null);

            $this->assertDirectoryDoesNotExist($root.'/'.$item->id);
        } finally {
            File::deleteDirectory($root);
        }
    }

    public function test_valid_remote_artwork_is_published_and_cached(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $item = MediaItem::query()->create([
            'user_id' => $user->id,
            'title' => 'Remote artwork',
            'media_kind' => 'video',
            'source_type' => 'iptv',
            'source_locator' => json_encode([
                'type' => 'movie',
                'id' => '701',
                'extension' => 'mp4',
            ], JSON_THROW_ON_ERROR),
            'metadata' => [
                'kind' => 'movie',
                'poster_url' => 'https://image.tmdb.org/t/p/w500/poster.jpg',
            ],
        ]);
        $root = storage_path('framework/testing-artwork-'.Str::ulid());
        config([
            'odissey.artwork_path' => $root,
            'odissey.caption_path' => $root.'-captions',
            'odissey.media_asset_min_free_bytes' => 0,
        ]);
        $jpeg = base64_decode(
            '/9j/4AAQSkZJRgABAQAAAQABAAD/2wBDAP//////////////////////////////////////////////////////////////////////////////////////2wBDAf//////////////////////////////////////////////////////////////////////////////////////wAARCAABAAEDASIAAhEBAxEB/8QAFQABAQAAAAAAAAAAAAAAAAAAAAX/xAAUEAEAAAAAAAAAAAAAAAAAAAAA/9oADAMBAAIQAxAAAAF//8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQABBQJ//8QAFBEBAAAAAAAAAAAAAAAAAAAAAP/aAAgBAwEBPwF//8QAFBEBAAAAAAAAAAAAAAAAAAAAAP/aAAgBAgEBPwF//8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQAGPwJ//8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQABPyF//9oADAMBAAIAAwAAABD/xAAUEQEAAAAAAAAAAAAAAAAAAAAA/9oACAEDAQE/EF//xAAUEQEAAAAAAAAAAAAAAAAAAAAA/9oACAECAQE/EF//xAAUEAEAAAAAAAAAAAAAAAAAAAAA/9oACAEBAAE/EF//2Q==',
            true,
        );
        $this->assertIsString($jpeg);
        $downloader = Mockery::mock(BoundedMediaDownloader::class);
        $downloader->shouldReceive('download')->once()->andReturn([
            'body' => $jpeg,
            'content_type' => 'image/jpeg',
            'final_url' => 'https://image.tmdb.org/t/p/w500/poster.jpg',
        ]);
        $artwork = new ArtworkManager(
            $downloader,
            app(MediaAssetStorage::class),
            app(MediaProcessFactory::class),
        );

        try {
            $artwork->populate($item, null);
            $item->refresh();

            $this->assertTrue($item->metadata['poster_cached']);
            $this->assertFileExists($root.'/'.$item->id.'/poster.jpg');
            $this->assertNotNull($artwork->path($item, 'poster'));
        } finally {
            File::deleteDirectory($root);
            File::deleteDirectory($root.'-captions');
        }
    }

    public function test_trusted_remote_artwork_is_cached_on_first_request(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $item = MediaItem::query()->create([
            'user_id' => $user->id,
            'title' => 'Artwork fallback',
            'media_kind' => 'video',
            'source_type' => 'iptv',
            'source_locator' => json_encode([
                'type' => 'movie',
                'id' => '501',
                'extension' => 'mp4',
            ], JSON_THROW_ON_ERROR),
            'metadata' => [
                'kind' => 'movie',
                'poster_url' => 'https://image.tmdb.org/t/p/w500/poster.jpg',
            ],
        ]);
        $path = storage_path('framework/testing-artwork.jpg');
        File::ensureDirectoryExists(dirname($path));
        File::put($path, 'jpeg fixture');

        $artwork = Mockery::mock(ArtworkManager::class);
        $artwork->shouldReceive('path')
            ->twice()
            ->withArgs(fn (MediaItem $candidate, string $kind): bool => (
                $candidate->is($item) && $kind === 'poster'
            ))
            ->andReturn(null, $path);
        $artwork->shouldReceive('populate')
            ->once()
            ->withArgs(fn (MediaItem $candidate, mixed $local): bool => (
                $candidate->is($item) && $local === null
            ));
        $this->app->instance(ArtworkManager::class, $artwork);

        try {
            $this->actingAs($user)
                ->get(route('media.artwork', [$item, 'poster']))
                ->assertOk()
                ->assertHeader('Content-Type', 'image/jpeg');
        } finally {
            File::delete($path);
        }
    }
}
