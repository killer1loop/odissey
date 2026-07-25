<?php

namespace Tests\Feature\Media;

use App\Models\MediaItem;
use App\Models\TranscodeSession;
use App\Models\User;
use App\Services\Media\FfmpegRunner;
use App\Services\Media\MediaFixtureGenerator;
use App\Services\Media\TranscodeStorage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class MediaFixtureCommandTest extends TestCase
{
    use RefreshDatabase;

    private string $temporaryPath;

    protected function setUp(): void
    {
        parent::setUp();

        if (! config('app.key')) {
            config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        }

        $this->temporaryPath = sys_get_temp_dir().'/odissey-fixture-tests-'.Str::uuid();
        config([
            'odissey.e2e_path' => $this->temporaryPath.'/e2e',
            'odissey.transcode_path' => $this->temporaryPath.'/transcodes',
        ]);

        $this->app->instance(FfmpegRunner::class, new class extends FfmpegRunner
        {
            public function run(
                array $arguments,
                int $timeoutSeconds,
                ?callable $shouldContinue = null,
            ): void {
                File::put($arguments[array_key_last($arguments)], 'synthetic media bytes');
            }
        });
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->temporaryPath);

        parent::tearDown();
    }

    public function test_fixtures_exist_only_after_the_explicit_generate_command_and_can_be_cleaned(): void
    {
        $user = User::factory()->create(['is_active' => true]);

        $this->assertFalse(File::exists(config('odissey.e2e_path')));
        $this->assertSame(0, MediaItem::query()->count());

        $this->artisan('media:e2e:generate', [
            'user' => $user->email,
            '--duration' => 2,
        ])->assertSuccessful();

        $this->assertTrue(File::isFile(config('odissey.e2e_path').'/direct-play.mp4'));
        $this->assertTrue(File::isFile(config('odissey.e2e_path').'/requires-transcode.mkv'));
        $this->assertDatabaseHas('media_items', [
            'user_id' => $user->getKey(),
            'title' => 'E2E Direct Play',
            'requires_transcode' => false,
        ]);
        $this->assertDatabaseHas('media_items', [
            'user_id' => $user->getKey(),
            'title' => 'E2E FFmpeg Transcode',
            'requires_transcode' => true,
        ]);

        $transcodeItem = MediaItem::query()
            ->where('requires_transcode', true)
            ->sole();
        $session = TranscodeSession::query()->create([
            'user_id' => $user->getKey(),
            'media_item_id' => $transcodeItem->getKey(),
            'status' => TranscodeSession::STATUS_READY,
            'expires_at' => now()->addMinute(),
        ]);
        $storage = app(TranscodeStorage::class);
        $storage->prepare($session);
        File::put($storage->manifestPath($session), '#EXTM3U');
        $this->assertTrue(File::isFile($storage->manifestPath($session)));

        $this->artisan('media:e2e:clean')->assertSuccessful();

        $this->assertFalse(File::exists(config('odissey.e2e_path')));
        $this->assertFalse(File::exists(config('odissey.transcode_path').'/'.$session->getKey()));
        $this->assertSame(0, MediaItem::query()->count());
    }

    public function test_cleanup_rejects_a_non_normalized_root_before_recursive_deletion(): void
    {
        $safeDirectory = $this->temporaryPath.'/safe';
        $dangerDirectory = $this->temporaryPath.'/danger';
        File::ensureDirectoryExists($safeDirectory);
        File::ensureDirectoryExists($dangerDirectory);
        File::put($dangerDirectory.'/keep.txt', 'must remain');
        config(['odissey.e2e_path' => $safeDirectory.'/../danger']);

        try {
            app(MediaFixtureGenerator::class)->clean();
            $this->fail('A root containing a parent-directory segment was accepted.');
        } catch (RuntimeException) {
            $this->assertTrue(File::isFile($dangerDirectory.'/keep.txt'));
        }
    }
}
