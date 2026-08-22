<?php

namespace Tests\Feature\Media;

use App\Jobs\Media\ScanMediaSource;
use App\Models\MediaSource;
use App\Services\Media\MediaScanProgress;
use App\Services\Media\Sources\MediaSourceRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Tests\TestCase;
use Throwable;

class ScanDiscoveryFailureTest extends TestCase
{
    use RefreshDatabase;

    public function test_discovery_failure_marks_the_source_failed_and_propagates(): void
    {
        if (! config('app.key')) {
            config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        }
        $root = sys_get_temp_dir().'/odissey-discovery-'.bin2hex(random_bytes(5));
        File::ensureDirectoryExists($root);
        config(['odissey.local_source_roots' => [$root]]);

        $scanToken = (string) Str::ulid();
        $source = MediaSource::create([
            'name' => 'Never created local root',
            'type' => 'local',
            'configuration' => [
                'path' => $root.'/never-created-'.Str::lower($scanToken),
            ],
            'active_scan_token' => $scanToken,
        ]);

        $job = new ScanMediaSource($source->id, $scanToken);

        try {
            $job->handle(
                app(MediaSourceRegistry::class),
                app(MediaScanProgress::class),
            );
            $this->fail('Discovery failures must propagate to the queue layer.');
        } catch (Throwable $exception) {
            $this->assertSame('source_unavailable', $exception->getMessage());
        }

        $failed = $source->refresh();
        $this->assertSame('failed', $failed->scan_status);
        $this->assertSame('source_scan_failed', $failed->last_error_code);
        $this->assertNull($failed->active_scan_token);

        File::deleteDirectory($root);
    }
}
