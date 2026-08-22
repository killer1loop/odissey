<?php

namespace Tests\Unit\Media;

use App\Services\Media\StallGuard;
use Tests\TestCase;

class StallGuardTest extends TestCase
{
    public function test_guard_expires_after_the_configured_budget(): void
    {
        config(['odissey.test_stall_seconds' => 1]);
        $guard = StallGuard::fromConfig('odissey.test_stall_seconds', 60);

        $this->assertFalse($guard->expired());

        sleep(2);

        $this->assertTrue($guard->expired());

        $guard->reset();

        $this->assertFalse($guard->expired());
    }

    public function test_configured_values_are_clamped_to_at_least_one_second(): void
    {
        config(['odissey.test_stall_seconds' => 0]);

        $this->assertSame(
            1,
            (function (): int {
                return $this->maximumSeconds;
            })->call(StallGuard::fromConfig('odissey.test_stall_seconds', 60)),
        );
    }
}
