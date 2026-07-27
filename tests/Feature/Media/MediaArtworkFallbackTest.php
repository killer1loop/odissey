<?php

namespace Tests\Feature\Media;

use App\Models\MediaItem;
use App\Models\User;
use App\Services\Media\ArtworkManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Mockery;
use Tests\TestCase;

class MediaArtworkFallbackTest extends TestCase
{
    use RefreshDatabase;

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
