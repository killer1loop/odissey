<?php

namespace Tests\Unit\Media;

use App\Services\Media\TranscodeConcurrencyGate;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class TranscodeConcurrencyGateTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'cache.default' => 'array',
            'odissey.max_transcodes' => 2,
        ]);
        Cache::flush();
    }

    public function test_only_the_configured_number_of_transcodes_can_hold_leases(): void
    {
        $gate = app(TranscodeConcurrencyGate::class);
        $first = $gate->acquire(30);
        $second = $gate->acquire(30);

        $this->assertNotNull($first);
        $this->assertNotNull($second);
        $this->assertNull($gate->acquire(30));

        $first->release();
        $replacement = $gate->acquire(30);

        $this->assertNotNull($replacement);

        $replacement->release();
        $second->release();
    }
}
