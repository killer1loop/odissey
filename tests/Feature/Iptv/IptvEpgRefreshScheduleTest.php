<?php

namespace Tests\Feature\Iptv;

use App\Jobs\Iptv\SyncIptvGuide;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\InteractsWithIptv;
use Tests\TestCase;

class IptvEpgRefreshScheduleTest extends TestCase
{
    use InteractsWithIptv;
    use RefreshDatabase;

    public function test_refresh_command_queues_a_guide_sync_for_each_enabled_provider(): void
    {
        Queue::fake();
        $enabled = $this->makeProvider();
        $secondEnabled = $this->makeProvider(['name' => 'Second IPTV']);
        $disabled = $this->makeProvider([
            'name' => 'Disabled IPTV',
            'enabled' => false,
        ]);

        $this->artisan('iptv:epg:refresh')
            ->expectsOutput('Queued EPG refresh for 2 enabled provider(s).')
            ->assertSuccessful();

        Queue::assertPushed(
            SyncIptvGuide::class,
            fn (SyncIptvGuide $job) => $job->providerId === $enabled->id,
        );
        Queue::assertPushed(
            SyncIptvGuide::class,
            fn (SyncIptvGuide $job) => $job->providerId === $secondEnabled->id,
        );
        Queue::assertNotPushed(
            SyncIptvGuide::class,
            fn (SyncIptvGuide $job) => $job->providerId === $disabled->id,
        );
        Queue::assertCount(2);
    }

    public function test_epg_refresh_is_scheduled_hourly_without_overlapping(): void
    {
        $event = collect(app(Schedule::class)->events())
            ->first(fn ($event) => str_contains(
                (string) $event->command,
                'iptv:epg:refresh',
            ));

        $this->assertNotNull($event);
        $this->assertSame('0 * * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping);
        $this->assertSame(10, $event->expiresAt);
    }

    public function test_failed_queue_records_are_pruned_each_day(): void
    {
        $event = collect(app(Schedule::class)->events())
            ->first(fn ($event) => str_contains(
                (string) $event->command,
                'queue:prune-failed --hours=168',
            ));

        $this->assertNotNull($event);
        $this->assertSame('37 2 * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping);
        $this->assertSame(10, $event->expiresAt);
    }
}
