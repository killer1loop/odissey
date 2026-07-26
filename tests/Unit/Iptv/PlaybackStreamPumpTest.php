<?php

namespace Tests\Unit\Iptv;

use App\Services\Iptv\PlaybackConcurrencyLease;
use App\Services\Iptv\PlaybackStreamPump;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Contracts\Cache\Lock;
use Mockery;
use Tests\TestCase;

class PlaybackStreamPumpTest extends TestCase
{
    public function test_expired_lease_prevents_stream_reads_and_releases_no_bytes(): void
    {
        $sessionLock = Mockery::mock(Lock::class);
        $providerLock = Mockery::mock(Lock::class);
        $sessionLock->shouldReceive('release')->once()->andReturnTrue();
        $providerLock->shouldReceive('release')->once()->andReturnTrue();
        $lease = new PlaybackConcurrencyLease(
            $sessionLock,
            $providerLock,
            leaseSeconds: 1,
            startedAtNanoseconds: hrtime(true) - 2_000_000_000,
        );
        $stream = Utils::streamFor('must-not-be-streamed');

        ob_start();
        app(PlaybackStreamPump::class)->pump($stream, $lease, 'prefix');
        $output = ob_get_clean();

        $this->assertSame('', $output);
        $this->assertTrue($lease->expired());
        $this->assertFalse($stream->isReadable());

        $lease->release();
    }

    public function test_lifetime_cap_never_extends_the_cache_lock_deadline(): void
    {
        $sessionLock = Mockery::mock(Lock::class);
        $providerLock = Mockery::mock(Lock::class);
        $sessionLock->shouldReceive('release')->once()->andReturnTrue();
        $providerLock->shouldReceive('release')->once()->andReturnTrue();
        $lease = new PlaybackConcurrencyLease(
            $sessionLock,
            $providerLock,
            leaseSeconds: 2,
        );
        $beforeCap = $lease->remainingNanoseconds();

        $lease->capLifetime(60);

        $this->assertLessThanOrEqual(
            $beforeCap,
            $lease->remainingNanoseconds(),
        );

        $lease->release();
    }
}
