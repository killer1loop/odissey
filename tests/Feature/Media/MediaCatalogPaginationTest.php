<?php

namespace Tests\Feature\Media;

use App\Models\MediaItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MediaCatalogPaginationTest extends TestCase
{
    use RefreshDatabase;

    public function test_movies_render_at_most_one_hundred_artwork_assets_per_page(): void
    {
        $viewer = User::factory()->create(['is_active' => true]);
        foreach (range(1, 101) as $number) {
            $this->video(
                $viewer,
                sprintf('Movie %03d', $number),
                ['kind' => 'movie'],
            );
        }

        $firstPage = $this->actingAs($viewer)->get(route('media.index', [
            'kind' => 'video',
            'library' => 'movies',
            'q' => 'Movie',
        ]));

        $firstPage
            ->assertOk()
            ->assertSee('Movie 001')
            ->assertSee('Movie 100')
            ->assertDontSee('Movie 101')
            ->assertSee('page=2', escape: false)
            ->assertSee('q=Movie', escape: false)
            ->assertSee('loading="lazy"', escape: false);
        $this->assertSame(
            100,
            substr_count($firstPage->getContent(), '/artwork/poster'),
        );

        $secondPage = $this->actingAs($viewer)->get(route('media.index', [
            'kind' => 'video',
            'library' => 'movies',
            'q' => 'Movie',
            'page' => 2,
        ]));

        $secondPage
            ->assertOk()
            ->assertSee('Movie 101')
            ->assertDontSee('Movie 001');
        $this->assertSame(
            1,
            substr_count($secondPage->getContent(), '/artwork/poster'),
        );
    }

    public function test_tv_shows_are_grouped_and_paginated_one_hundred_per_page(): void
    {
        $viewer = User::factory()->create(['is_active' => true]);
        foreach (range(1, 101) as $number) {
            $title = sprintf('Show %03d', $number);
            $this->video($viewer, $title, [
                'kind' => 'series',
                'series_title' => $title,
            ]);
        }
        foreach (range(1, 3) as $episode) {
            $this->video($viewer, 'Show 001 episode '.$episode, [
                'kind' => 'episode',
                'series_title' => 'Show 001',
                'season_number' => 1,
                'episode_number' => $episode,
            ]);
        }

        $firstPage = $this->actingAs($viewer)->get(route('media.index', [
            'kind' => 'video',
            'library' => 'tv',
        ]));

        $firstPage
            ->assertOk()
            ->assertSee('Show 001')
            ->assertSee('Show 100')
            ->assertSee('3 episodes')
            ->assertDontSee('Show 101')
            ->assertSee('page=2', escape: false)
            ->assertSee('loading="lazy"', escape: false);
        $this->assertSame(
            100,
            substr_count($firstPage->getContent(), '/artwork/poster'),
        );

        $secondPage = $this->actingAs($viewer)->get(route('media.index', [
            'kind' => 'video',
            'library' => 'tv',
            'page' => 2,
        ]));

        $secondPage
            ->assertOk()
            ->assertSee('Show 101')
            ->assertDontSee('Show 001');
        $this->assertSame(
            1,
            substr_count($secondPage->getContent(), '/artwork/poster'),
        );
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function video(User $owner, string $title, array $metadata): MediaItem
    {
        return MediaItem::query()->create([
            'user_id' => $owner->id,
            'title' => $title,
            'media_kind' => 'video',
            'source_type' => 'local',
            'source_locator' => '/media/'.$title.'.mp4',
            'mime_type' => 'video/mp4',
            'container' => 'mp4',
            'metadata' => [
                ...$metadata,
                'poster_url' => 'https://image.tmdb.org/t/p/w500/poster.jpg',
            ],
        ]);
    }
}
