<?php

namespace Tests\Feature\Media;

use App\Models\MediaItem;
use App\Models\TranscodeSession;
use App\Models\User;
use App\Services\Media\TranscodeStorage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Tests\TestCase;

class TranscodePruneCommandTest extends TestCase
{
    use RefreshDatabase;

    private MediaItem $item;

    private TranscodeStorage $storage;

    private string $temporaryPath;

    protected function setUp(): void
    {
        parent::setUp();

        if (! config('app.key')) {
            config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        }

        $this->temporaryPath = sys_get_temp_dir().'/odissey-prune-tests-'.Str::uuid();
        config([
            'odissey.transcode_failed_retention_minutes' => 5,
            'odissey.transcode_path' => $this->temporaryPath.'/transcodes',
            'odissey.transcode_stale_minutes' => 10,
        ]);
        $user = User::factory()->create(['is_active' => true]);
        $this->item = MediaItem::query()->create([
            'user_id' => $user->getKey(),
            'title' => 'Prune fixture',
            'source_type' => 'local',
            'source_locator' => $this->temporaryPath.'/source.mkv',
            'requires_transcode' => true,
        ]);
        $this->storage = app(TranscodeStorage::class);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->temporaryPath);

        parent::tearDown();
    }

    public function test_command_dry_run_is_read_only_and_then_prunes_only_safe_candidates(): void
    {
        $expired = $this->makeSession([
            'status' => TranscodeSession::STATUS_READY,
            'expires_at' => now()->subMinute(),
        ]);
        $failed = $this->makeSession([
            'status' => TranscodeSession::STATUS_FAILED,
            'finished_at' => now()->subMinutes(10),
        ]);
        DB::table('transcode_sessions')
            ->where('id', $failed->getKey())
            ->update(['updated_at' => now()->subMinutes(10)]);
        $stale = $this->makeSession([
            'status' => TranscodeSession::STATUS_PROCESSING,
            'started_at' => now()->subMinutes(20),
        ]);
        $current = $this->makeSession([
            'status' => TranscodeSession::STATUS_READY,
            'expires_at' => now()->addHour(),
        ]);

        foreach ([$expired, $failed, $stale, $current] as $session) {
            $this->storage->prepare($session);
            File::put($this->storage->manifestPath($session), '#EXTM3U');
        }

        $orphanId = (string) Str::ulid();
        $orphanDirectory = config('odissey.transcode_path').'/'.$orphanId;
        File::ensureDirectoryExists($orphanDirectory);
        File::put($orphanDirectory.'/index.m3u8', '#EXTM3U');
        touch($orphanDirectory, now()->subMinutes(20)->timestamp);

        $this->artisan('media:transcodes:prune', ['--dry-run' => true])
            ->assertSuccessful();

        $this->assertSame(4, TranscodeSession::query()->count());
        $this->assertTrue(File::isDirectory($orphanDirectory));

        $this->artisan('media:transcodes:prune')->assertSuccessful();

        $this->assertDatabaseMissing('transcode_sessions', ['id' => $expired->getKey()]);
        $this->assertDatabaseMissing('transcode_sessions', ['id' => $failed->getKey()]);
        $this->assertDatabaseMissing('transcode_sessions', ['id' => $stale->getKey()]);
        $this->assertDatabaseHas('transcode_sessions', ['id' => $current->getKey()]);
        $this->assertFalse(File::isDirectory($orphanDirectory));
        $this->assertTrue(
            File::isDirectory(config('odissey.transcode_path').'/'.$current->getKey()),
        );
    }

    public function test_dry_run_does_not_create_a_missing_transcode_root(): void
    {
        $this->assertFalse(File::exists(config('odissey.transcode_path')));

        $this->artisan('media:transcodes:prune', ['--dry-run' => true])
            ->assertSuccessful();

        $this->assertFalse(File::exists(config('odissey.transcode_path')));
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function makeSession(array $attributes): TranscodeSession
    {
        return TranscodeSession::query()->create([
            'user_id' => $this->item->user_id,
            'media_item_id' => $this->item->getKey(),
            'status' => TranscodeSession::STATUS_PENDING,
            ...$attributes,
        ]);
    }
}
