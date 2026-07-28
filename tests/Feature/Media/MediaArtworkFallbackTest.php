<?php

namespace Tests\Feature\Media;

use App\Models\MediaItem;
use App\Models\User;
use App\Services\Media\ArtworkConcurrencyGate;
use App\Services\Media\ArtworkManager;
use App\Services\Media\BoundedMediaDownloader;
use App\Services\Media\MediaAssetStorage;
use App\Services\Media\MediaProcessFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Mockery;
use RuntimeException;
use Symfony\Component\Process\Process;
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
        File::ensureDirectoryExists(
            $root.'/'.$item->id.'/variants',
            0700,
        );
        File::put(
            $root.'/'.$item->id.'/variants/stale.jpg',
            'stale',
        );
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
            app(ArtworkConcurrencyGate::class),
        );

        try {
            $artwork->populate($item, null);
            $item->refresh();

            $this->assertTrue($item->metadata['poster_cached']);
            $this->assertFileExists($root.'/'.$item->id.'/poster.jpg');
            $this->assertDirectoryDoesNotExist(
                $root.'/'.$item->id.'/variants',
            );
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

    public function test_nearby_variant_sizes_share_a_bucket_and_stale_hashes_are_pruned(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $item = MediaItem::query()->create([
            'user_id' => $user->id,
            'title' => 'Bucketed artwork',
            'media_kind' => 'video',
            'source_type' => 'local',
            'source_locator' => '/media/bucketed.mp4',
            'metadata' => [
                'kind' => 'movie',
                'poster_cached' => true,
            ],
        ]);
        $root = storage_path('framework/testing-artwork-'.Str::ulid());
        $directory = $root.'/'.$item->id;
        File::ensureDirectoryExists($directory.'/variants', 0700);
        File::put(
            $directory.'/poster.jpg',
            $this->jpegWithDimensions(1000, 500),
        );
        $stale = $directory
            .'/variants/poster-320x160-deadbeefdeadbeef.jpg';
        File::put($stale, $this->jpegWithDimensions(320, 160));
        config([
            'odissey.artwork_path' => $root,
            'odissey.caption_path' => $root.'-captions',
            'odissey.media_asset_min_free_bytes' => 0,
        ]);

        $output = $this->jpegWithDimensions(480, 240);
        $process = Mockery::mock(Process::class);
        $process->shouldReceive('run')->once()->andReturn(0);
        $process->shouldReceive('isSuccessful')->once()->andReturnTrue();
        $factory = Mockery::mock(MediaProcessFactory::class);
        $factory->shouldReceive('make')
            ->once()
            ->withArgs(function (
                array $arguments,
                int $timeout,
            ) use ($output): bool {
                $this->assertContains('scale=480:240', $arguments);
                $this->assertSame(30, $timeout);
                File::put(
                    $arguments[array_key_last($arguments)],
                    $output,
                );

                return true;
            })
            ->andReturn($process);
        $artwork = new ArtworkManager(
            Mockery::mock(BoundedMediaDownloader::class),
            app(MediaAssetStorage::class),
            $factory,
            app(ArtworkConcurrencyGate::class),
        );

        try {
            $first = $artwork->variantPath(
                $item,
                'poster',
                501,
                null,
            );
            $second = $artwork->variantPath(
                $item,
                'poster',
                719,
                null,
            );

            $this->assertSame($first, $second);
            $this->assertIsString($first);
            $this->assertFileExists($first);
            $this->assertStringContainsString(
                'poster-480x240-',
                $first,
            );
            $this->assertFileDoesNotExist($stale);
            $this->assertCount(
                1,
                File::files($directory.'/variants'),
            );
        } finally {
            File::deleteDirectory($root);
            File::deleteDirectory($root.'-captions');
        }
    }

    public function test_resizing_failure_serves_original_with_cache_validators(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $item = MediaItem::query()->create([
            'user_id' => $user->id,
            'title' => 'Original fallback',
            'media_kind' => 'video',
            'source_type' => 'local',
            'source_locator' => '/media/original.mp4',
            'metadata' => [
                'kind' => 'movie',
                'poster_cached' => true,
            ],
        ]);
        $path = storage_path(
            'framework/testing-artwork-'.Str::ulid().'.jpg',
        );
        File::put($path, $this->jpegWithDimensions(1000, 500));
        $artwork = Mockery::mock(ArtworkManager::class);
        $artwork->shouldReceive('path')
            ->twice()
            ->withArgs(fn (MediaItem $candidate, string $kind): bool => (
                $candidate->is($item) && $kind === 'poster'
            ))
            ->andReturn($path);
        $artwork->shouldReceive('variantPath')
            ->twice()
            ->andThrow(new RuntimeException('resize failed'));
        $this->app->instance(ArtworkManager::class, $artwork);

        try {
            $response = $this->actingAs($user)
                ->get(route('media.artwork', [$item, 'poster'])
                    .'?width=501')
                ->assertOk()
                ->assertHeader('Content-Type', 'image/jpeg')
                ->assertHeader('ETag')
                ->assertHeader('Last-Modified');
            $this->assertStringContainsString(
                'private',
                (string) $response->headers->get('Cache-Control'),
            );
            $this->actingAs($user)
                ->withHeader(
                    'If-None-Match',
                    (string) $response->headers->get('ETag'),
                )
                ->get(route('media.artwork', [$item, 'poster'])
                    .'?width=501')
                ->assertNotModified();
        } finally {
            File::delete($path);
        }
    }

    public function test_busy_variant_or_process_admission_serves_the_original_without_spawning_ffmpeg(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $item = MediaItem::query()->create([
            'user_id' => $user->id,
            'title' => 'Admission-controlled artwork',
            'media_kind' => 'video',
            'source_type' => 'local',
            'source_locator' => '/media/admission-controlled.mp4',
            'metadata' => [
                'kind' => 'movie',
                'poster_cached' => true,
            ],
        ]);
        $root = storage_path('framework/testing-artwork-'.Str::ulid());
        $directory = $root.'/'.$item->id;
        File::ensureDirectoryExists($directory, 0700);
        $source = $directory.'/poster.jpg';
        File::put($source, $this->jpegWithDimensions(1000, 500));
        config([
            'cache.default' => 'array',
            'odissey.runtime_cache_store' => 'array',
            'odissey.artwork_path' => $root,
            'odissey.caption_path' => $root.'-captions',
            'odissey.artwork_max_processes' => 1,
            'odissey.media_asset_min_free_bytes' => 0,
        ]);
        Cache::store('array')->flush();
        $factory = Mockery::mock(MediaProcessFactory::class);
        $factory->shouldNotReceive('make');
        $gate = app(ArtworkConcurrencyGate::class);
        $artwork = new ArtworkManager(
            Mockery::mock(BoundedMediaDownloader::class),
            app(MediaAssetStorage::class),
            $factory,
            $gate,
        );
        $sourceHash = hash_file('sha256', $source);
        $this->assertIsString($sourceHash);
        $variantKey = implode('|', [
            (string) $item->getKey(),
            'poster',
            '480',
            '240',
            $sourceHash,
        ]);

        $variantLock = $gate->acquireVariant($variantKey, 45);
        $this->assertNotNull($variantLock);
        try {
            $this->assertSame(
                $source,
                $artwork->variantPath($item, 'poster', 501, null),
            );
        } finally {
            $variantLock->release();
        }

        $processLock = $gate->acquire(45);
        $this->assertNotNull($processLock);
        try {
            $this->assertSame(
                $source,
                $artwork->variantPath($item, 'poster', 501, null),
            );
            $extraction = MediaItem::query()->create([
                'user_id' => $user->id,
                'title' => 'Admission-controlled extraction',
                'media_kind' => 'video',
                'source_type' => 'local',
                'source_locator' => '/media/admission-extraction.mp4',
                'metadata' => ['kind' => 'movie'],
            ]);
            $artwork->populate(
                $extraction,
                '/media/admission-extraction.mp4',
            );
            $this->assertFalse(
                $extraction->refresh()->metadata['poster_cached'],
            );
        } finally {
            $processLock->release();
            File::deleteDirectory($root);
            File::deleteDirectory($root.'-captions');
        }
    }

    public function test_browser_and_native_artwork_routes_have_an_isolated_owner_rate_limit(): void
    {
        foreach (['media.artwork', 'api.v1.media.artwork'] as $name) {
            $route = app('router')->getRoutes()->getByName($name);

            $this->assertNotNull($route);
            $this->assertContains(
                'throttle:180,1,media-artwork:',
                $route->gatherMiddleware(),
            );
        }
    }

    public function test_artwork_dimensions_are_hard_bounded(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $item = MediaItem::query()->create([
            'user_id' => $user->id,
            'title' => 'Bounded artwork',
            'media_kind' => 'video',
            'source_type' => 'local',
            'source_locator' => '/media/bounded.mp4',
            'metadata' => ['kind' => 'movie'],
        ]);

        $this->withToken($this->nativeToken($user))
            ->getJson(route('api.v1.media.artwork', [$item, 'poster'])
                    .'?width=31')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('width');
    }

    public function test_artwork_pixel_boxes_are_hard_bounded(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $item = MediaItem::query()->create([
            'user_id' => $user->id,
            'title' => 'Pixel-bounded artwork',
            'media_kind' => 'video',
            'source_type' => 'local',
            'source_locator' => '/media/pixel-bounded.mp4',
            'metadata' => ['kind' => 'movie'],
        ]);

        $this->withToken($this->nativeToken($user))
            ->getJson(route('api.v1.media.artwork', [$item, 'poster'])
                .'?width=3840&height=3840')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('width');
    }

    private function jpegWithDimensions(
        int $width,
        int $height,
    ): string {
        $jpeg = base64_decode(
            '/9j/4AAQSkZJRgABAQAAAQABAAD/2wBDAP//////////////////////////////////////////////////////////////////////////////////////2wBDAf//////////////////////////////////////////////////////////////////////////////////////wAARCAABAAEDASIAAhEBAxEB/8QAFQABAQAAAAAAAAAAAAAAAAAAAAX/xAAUEAEAAAAAAAAAAAAAAAAAAAAA/9oADAMBAAIQAxAAAAF//8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQABBQJ//8QAFBEBAAAAAAAAAAAAAAAAAAAAAP/aAAgBAwEBPwF//8QAFBEBAAAAAAAAAAAAAAAAAAAAAP/aAAgBAgEBPwF//8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQAGPwJ//8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQABPyF//9oADAMBAAIAAwAAABD/xAAUEQEAAAAAAAAAAAAAAAAAAAAA/9oACAEDAQE/EF//xAAUEQEAAAAAAAAAAAAAAAAAAAAA/9oACAECAQE/EF//xAAUEAEAAAAAAAAAAAAAAAAAAAAA/9oACAEBAAE/EF//2Q==',
            true,
        );
        $this->assertIsString($jpeg);
        $startOfFrame = strpos($jpeg, "\xFF\xC0");
        $this->assertIsInt($startOfFrame);

        return substr_replace(
            $jpeg,
            pack('n', $height).pack('n', $width),
            $startOfFrame + 5,
            4,
        );
    }

    private function nativeToken(User $user): string
    {
        $user->forceFill(['password' => 'VeryStrong!123'])->save();

        return (string) $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'VeryStrong!123',
            'device' => [
                'installationId' => 'artwork-'.Str::random(24),
                'deviceName' => 'Artwork Test TV',
                'platform' => 'tvOS',
                'appVersion' => '1.0',
                'osVersion' => '26.5',
            ],
        ])->assertOk()->json('accessToken');
    }
}
