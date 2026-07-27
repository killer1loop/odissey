<?php

namespace Tests\Feature\Media;

use App\Jobs\Media\TranscodeMediaToHls;
use App\Models\MediaItem;
use App\Models\PlaybackHistory;
use App\Models\PlaybackProgress;
use App\Models\TranscodeSession;
use App\Models\User;
use App\Services\Media\TranscodeStorage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class MediaPlaybackTest extends TestCase
{
    use RefreshDatabase;

    private string $temporaryPath;

    protected function setUp(): void
    {
        parent::setUp();

        if (! config('app.key')) {
            config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        }

        $this->temporaryPath = sys_get_temp_dir().'/odissey-media-tests-'.Str::uuid();
        File::ensureDirectoryExists($this->temporaryPath);
        config([
            'odissey.e2e_path' => $this->temporaryPath.'/e2e',
            'odissey.transcode_path' => $this->temporaryPath.'/transcodes',
        ]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->temporaryPath);

        parent::tearDown();
    }

    public function test_media_routes_require_an_active_authenticated_user(): void
    {
        $this->get(route('media.index'))
            ->assertRedirect(route('login'));

        $disabledUser = User::factory()->create(['is_active' => false]);

        $this->actingAs($disabledUser)
            ->get(route('media.index'))
            ->assertRedirect(route('login'));
    }

    public function test_media_catalog_and_direct_files_are_scoped_to_the_owner(): void
    {
        $owner = User::factory()->create(['is_active' => true]);
        $otherUser = User::factory()->create(['is_active' => true]);
        $path = $this->temporaryPath.'/sample.mp4';
        File::put($path, '0123456789');
        $item = $this->mediaItem($owner, $path);

        $this->actingAs($owner)
            ->get(route('media.index'))
            ->assertOk()
            ->assertSee($item->title);
        $this->actingAs($owner)
            ->get(route('media.show', $item))
            ->assertOk()
            ->assertSee('data-media-player', escape: false)
            ->assertSee('media-player-shell', escape: false)
            ->assertSee('data-player-fullscreen', escape: false)
            ->assertSee('data-player-ambient', escape: false)
            ->assertSee('>History<', escape: false)
            ->assertSee('F</kbd> full screen', escape: false);

        $this->assertDatabaseHas('playback_history', [
            'user_id' => $owner->getKey(),
            'media_item_id' => $item->getKey(),
            'event' => 'started',
        ]);

        $this->actingAs($owner)
            ->withHeader('Range', 'bytes=2-5')
            ->get(route('media.direct', $item))
            ->assertStatus(206)
            ->assertHeader('Content-Range', 'bytes 2-5/10')
            ->assertHeader('Accept-Ranges', 'bytes');

        $this->actingAs($otherUser)
            ->get(route('media.show', $item))
            ->assertNotFound();
        $this->actingAs($otherUser)
            ->get(route('media.direct', $item))
            ->assertNotFound();
    }

    public function test_progress_heartbeats_are_monotonic_and_isolated_per_user(): void
    {
        $owner = User::factory()->create(['is_active' => true]);
        $otherUser = User::factory()->create(['is_active' => true]);
        $item = $this->mediaItem($owner, $this->temporaryPath.'/sample.mp4');

        $this->actingAs($owner)
            ->putJson(route('media.progress', $item), [
                'sequence' => 2,
                'position_ms' => 20_000,
                'duration_ms' => 60_000,
            ])
            ->assertOk()
            ->assertJson(['accepted' => true, 'sequence' => 2]);

        $this->actingAs($owner)
            ->putJson(route('media.progress', $item), [
                'sequence' => 1,
                'position_ms' => 5_000,
                'duration_ms' => 60_000,
            ])
            ->assertOk()
            ->assertJson(['accepted' => false, 'sequence' => 2]);

        $this->assertDatabaseHas('playback_progress', [
            'user_id' => $owner->getKey(),
            'media_item_id' => $item->getKey(),
            'position_ms' => 20_000,
            'sequence' => 2,
        ]);

        $this->actingAs($owner)
            ->putJson(route('media.progress', $item), [
                'sequence' => 3,
                'position_ms' => 60_000,
                'duration_ms' => 60_000,
                'completed' => true,
            ])
            ->assertOk()
            ->assertJson(['accepted' => true, 'sequence' => 3]);

        $this->assertDatabaseHas('playback_history', [
            'user_id' => $owner->getKey(),
            'media_item_id' => $item->getKey(),
            'event' => 'completed',
            'position_ms' => 60_000,
        ]);

        $this->actingAs($otherUser)
            ->putJson(route('media.progress', $item), [
                'sequence' => 3,
                'position_ms' => 30_000,
            ])
            ->assertNotFound();

        $this->assertSame(1, PlaybackProgress::query()->count());
    }

    public function test_video_player_history_rail_shows_progress_and_remaining_time(): void
    {
        $owner = User::factory()->create(['is_active' => true]);
        $current = $this->mediaItem(
            $owner,
            $this->temporaryPath.'/current.mp4',
            title: 'Current movie',
        );
        $previous = $this->mediaItem(
            $owner,
            $this->temporaryPath.'/previous.mp4',
            title: 'Previous movie',
        );
        $previous->forceFill(['duration_ms' => 3_600_000])->save();

        PlaybackProgress::query()->create([
            'user_id' => $owner->getKey(),
            'media_item_id' => $previous->getKey(),
            'position_ms' => 1_800_000,
            'duration_ms' => 3_600_000,
            'sequence' => 3,
        ]);
        PlaybackHistory::query()->create([
            'user_id' => $owner->getKey(),
            'media_item_id' => $previous->getKey(),
            'event' => 'progress',
            'position_ms' => 1_800_000,
            'watched_ms' => 60_000,
            'played_at' => now()->subMinute(),
        ]);

        $this->actingAs($owner)
            ->get(route('media.show', $current))
            ->assertOk()
            ->assertSee('data-player-history-rail', escape: false)
            ->assertSee('data-player-history-item', escape: false)
            ->assertSee('Previous movie')
            ->assertSee('50% watched · 30m remaining')
            ->assertSee('aria-valuenow="50"', escape: false)
            ->assertSee(route('media.show', $previous));
    }

    public function test_starting_a_transcode_creates_a_database_queued_job(): void
    {
        Queue::fake();
        $owner = User::factory()->create(['is_active' => true]);
        $item = $this->mediaItem(
            $owner,
            $this->temporaryPath.'/unsupported.mkv',
            requiresTranscode: true,
        );

        $this->actingAs($owner)
            ->post(route('media.transcodes.store', $item))
            ->assertRedirect(route('media.show', $item));

        $session = TranscodeSession::query()->sole();

        $this->assertSame(TranscodeSession::STATUS_PENDING, $session->status);
        $this->assertSame($owner->getKey(), $session->user_id);
        Queue::assertPushed(
            TranscodeMediaToHls::class,
            fn (TranscodeMediaToHls $job): bool => $job->sessionId === $session->getKey()
                && $job->queue === 'high',
        );
    }

    public function test_missing_ephemeral_output_does_not_leave_a_ready_session_stuck(): void
    {
        Queue::fake();
        $owner = User::factory()->create(['is_active' => true]);
        $item = $this->mediaItem(
            $owner,
            $this->temporaryPath.'/unsupported.mkv',
            requiresTranscode: true,
        );
        $staleSession = TranscodeSession::query()->create([
            'user_id' => $owner->getKey(),
            'media_item_id' => $item->getKey(),
            'status' => TranscodeSession::STATUS_READY,
            'manifest_relative_path' => 'index.m3u8',
            'expires_at' => now()->addMinute(),
        ]);

        $this->actingAs($owner)
            ->get(route('media.show', $item))
            ->assertOk()
            ->assertSee('Prepare HLS stream');

        $this->assertDatabaseMissing('transcode_sessions', [
            'id' => $staleSession->getKey(),
        ]);

        $this->actingAs($owner)
            ->post(route('media.transcodes.store', $item))
            ->assertRedirect(route('media.show', $item));

        $newSession = TranscodeSession::query()->sole();

        $this->assertSame(TranscodeSession::STATUS_PENDING, $newSession->status);
        Queue::assertPushed(
            TranscodeMediaToHls::class,
            fn (TranscodeMediaToHls $job): bool => $job->sessionId === $newSession->getKey(),
        );
    }

    public function test_starting_a_transcode_replaces_ready_metadata_when_output_is_missing(): void
    {
        Queue::fake();
        $owner = User::factory()->create(['is_active' => true]);
        $item = $this->mediaItem(
            $owner,
            $this->temporaryPath.'/unsupported.mkv',
            requiresTranscode: true,
        );
        $staleSession = TranscodeSession::query()->create([
            'user_id' => $owner->getKey(),
            'media_item_id' => $item->getKey(),
            'status' => TranscodeSession::STATUS_READY,
            'manifest_relative_path' => 'index.m3u8',
            'expires_at' => now()->addMinute(),
        ]);

        $this->actingAs($owner)
            ->post(route('media.transcodes.store', $item))
            ->assertRedirect(route('media.show', $item));

        $this->assertDatabaseMissing('transcode_sessions', [
            'id' => $staleSession->getKey(),
        ]);

        $newSession = TranscodeSession::query()->sole();

        $this->assertSame(TranscodeSession::STATUS_PENDING, $newSession->status);
        Queue::assertPushed(
            TranscodeMediaToHls::class,
            fn (TranscodeMediaToHls $job): bool => $job->sessionId === $newSession->getKey(),
        );
    }

    public function test_hls_manifests_and_segments_require_session_ownership(): void
    {
        $owner = User::factory()->create(['is_active' => true]);
        $otherUser = User::factory()->create(['is_active' => true]);
        $item = $this->mediaItem(
            $owner,
            $this->temporaryPath.'/unsupported.mkv',
            requiresTranscode: true,
        );
        $session = TranscodeSession::query()->create([
            'user_id' => $owner->getKey(),
            'media_item_id' => $item->getKey(),
            'status' => TranscodeSession::STATUS_READY,
            'manifest_relative_path' => 'index.m3u8',
            'expires_at' => now()->addMinute(),
        ]);
        $storage = app(TranscodeStorage::class);
        $storage->prepare($session);
        File::put($storage->manifestPath($session), "#EXTM3U\nsegment-00000.ts\n");
        File::put($storage->segmentPath($session, 'segment-00000.ts'), 'segment bytes');

        $this->actingAs($owner)
            ->get(route('media.transcodes.manifest', [$item, $session]))
            ->assertOk()
            ->assertHeader('Content-Type', 'application/vnd.apple.mpegurl')
            ->assertSee('segment-00000.ts');

        $this->actingAs($owner)
            ->get(route('media.transcodes.segment', [$item, $session, 'segment-00000.ts']))
            ->assertOk()
            ->assertHeader('Content-Type', 'video/mp2t');

        $this->actingAs($otherUser)
            ->get(route('media.transcodes.manifest', [$item, $session]))
            ->assertNotFound();
        $this->actingAs($otherUser)
            ->get(route('media.transcodes.segment', [$item, $session, 'segment-00000.ts']))
            ->assertNotFound();
    }

    private function mediaItem(
        User $owner,
        string $path,
        bool $requiresTranscode = false,
        string $title = 'Owned sample video',
    ): MediaItem {
        return MediaItem::query()->create([
            'user_id' => $owner->getKey(),
            'title' => $title,
            'source_type' => 'local',
            'source_locator' => $path,
            'mime_type' => $requiresTranscode ? 'video/x-matroska' : 'video/mp4',
            'container' => $requiresTranscode ? 'matroska' : 'mp4',
            'video_codec' => $requiresTranscode ? 'ffv1' : 'h264',
            'audio_codec' => $requiresTranscode ? 'pcm_s16le' : 'aac',
            'duration_ms' => 60_000,
            'requires_transcode' => $requiresTranscode,
        ]);
    }
}
