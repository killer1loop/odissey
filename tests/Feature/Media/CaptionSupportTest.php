<?php

namespace Tests\Feature\Media;

use App\Models\MediaItem;
use App\Models\MediaSubtitle;
use App\Models\User;
use App\Services\Media\Captions\CaptionCandidate;
use App\Services\Media\Captions\CaptionStorage;
use App\Services\Media\Captions\SubdlCaptionProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CaptionSupportTest extends TestCase
{
    use RefreshDatabase;

    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();
        if (! config('app.key')) {
            config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        }
        $this->directory = sys_get_temp_dir().'/odissey-captions-'.bin2hex(random_bytes(5));
        config(['odissey.caption_path' => $this->directory, 'services.subdl.api_key' => 'free-key']);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->directory);
        parent::tearDown();
    }

    public function test_subdl_search_uses_free_api_and_normalizes_episode_candidate(): void
    {
        Http::fake(['api.subdl.com/*' => Http::response([
            'status' => true, 'subtitles' => [[
                'unpack_files' => [[
                    'file_n_id' => 'caption-1', 'language' => 'EN', 'episode' => 2,
                    'name' => 'Episode.srt', 'url' => '/subtitle/pack/caption-1',
                ]],
            ]],
        ])]);
        $item = $this->item(['kind' => 'episode', 'series_title' => 'Example', 'season_number' => 1, 'episode_number' => 2, 'tmdb_id' => 42]);
        $results = app(SubdlCaptionProvider::class)->search($item, ['en']);
        $this->assertCount(1, $results);
        $this->assertSame('https://dl.subdl.com/subtitle/pack/caption-1', $results[0]->downloadUrl);
    }

    public function test_downloaded_webvtt_is_private_and_available_to_the_media_owner(): void
    {
        $user = User::factory()->create();
        $item = $this->item([], $user);
        $candidate = new CaptionCandidate('subdl', 'caption-1', 'en', 'English', 'https://dl.subdl.com/subtitle/1');
        $path = app(CaptionStorage::class)->store($item, $candidate, "WEBVTT\n\n00:00:00.000 --> 00:00:01.000\nHello\n");
        $caption = MediaSubtitle::create(['media_item_id' => $item->id, 'provider' => 'subdl', 'external_id' => 'caption-1', 'language' => 'en', 'label' => 'English', 'path' => $path]);
        $this->get(route('media.captions.show', [$item, $caption]))->assertRedirect(route('login'));
        $this->actingAs($user)->get(route('media.captions.show', [$item, $caption]))->assertOk()->assertHeader('Content-Type', 'text/vtt; charset=UTF-8');
        $this->assertStringNotContainsString($path, $caption->getRawOriginal('path'));
    }

    public function test_admin_can_store_provider_keys_encrypted_without_readback(): void
    {
        $admin = User::factory()->create(['is_admin' => true, 'is_active' => true]);
        $this->actingAs($admin)->put(route('media.admin.integrations.update'), [
            'tmdb_api_token' => 'private-tmdb-token',
            'subdl_api_key' => 'private-subdl-key',
            'opensubtitles_api_key' => 'private-open-key',
            'caption_languages' => 'en,it',
        ])->assertRedirect();
        $raw = DB::table('integration_settings')->pluck('value')->implode(' ');
        $this->assertStringNotContainsString('private-', $raw);
        $this->actingAs($admin)->get(route('media.admin.integrations.edit'))
            ->assertOk()->assertSee('configured')->assertDontSee('private-tmdb-token');
    }

    private function item(array $metadata = [], ?User $user = null): MediaItem
    {
        $user ??= User::factory()->create(['is_admin' => true]);

        return MediaItem::create([
            'user_id' => $user->id, 'title' => 'Example', 'media_kind' => 'video',
            'source_type' => 'e2e', 'source_locator' => '/tmp/example.mp4',
            'mime_type' => 'video/mp4', 'container' => 'mp4', 'metadata' => $metadata,
        ]);
    }
}
