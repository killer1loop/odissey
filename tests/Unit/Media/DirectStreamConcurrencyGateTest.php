<?php

namespace Tests\Unit\Media;

use App\Services\Media\DirectStreamConcurrencyGate;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class DirectStreamConcurrencyGateTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'cache.default' => 'array',
            'odissey.remote_stream_global_concurrency' => 2,
            'odissey.remote_stream_source_concurrency' => 1,
            'odissey.remote_stream_user_concurrency' => 1,
            'odissey.remote_stream_lease_seconds' => 90,
        ]);
        Cache::flush();
    }

    public function test_global_source_and_user_admission_scopes_are_all_enforced(): void
    {
        $gate = app(DirectStreamConcurrencyGate::class);
        $first = $gate->acquire('user-one', 'source-one');

        $this->assertNotNull($first);
        $this->assertNull($gate->acquire('user-one', 'source-two'));
        $this->assertNull($gate->acquire('user-two', 'source-one'));

        $second = $gate->acquire('user-two', 'source-two');
        $this->assertNotNull($second);
        $this->assertNull($gate->acquire('user-three', 'source-three'));

        $first->release();
        $replacement = $gate->acquire('user-three', 'source-three');

        $this->assertNotNull($replacement);

        $replacement->release();
        $replacement->release();
        $second->release();
    }
}
