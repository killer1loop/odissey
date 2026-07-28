<?php

namespace Tests\Unit\Media;

use App\Services\Media\ArtworkConcurrencyGate;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class ArtworkConcurrencyGateTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'cache.default' => 'array',
            'odissey.runtime_cache_store' => 'array',
            'odissey.artwork_max_processes' => 2,
        ]);
        Cache::store('array')->flush();
    }

    public function test_global_process_slots_are_bounded_and_reusable(): void
    {
        $gate = app(ArtworkConcurrencyGate::class);
        $first = $gate->acquire(45);
        $second = $gate->acquire(45);

        $this->assertNotNull($first);
        $this->assertNotNull($second);
        $this->assertNull($gate->acquire(45));

        $first->release();
        $replacement = $gate->acquire(45);

        $this->assertNotNull($replacement);

        $replacement->release();
        $second->release();
    }

    public function test_variant_generation_is_serialized_by_exact_key(): void
    {
        $gate = app(ArtworkConcurrencyGate::class);
        $first = $gate->acquireVariant('media|poster|480x240|hash', 45);

        $this->assertNotNull($first);
        $this->assertNull(
            $gate->acquireVariant('media|poster|480x240|hash', 45),
        );
        $other = $gate->acquireVariant(
            'media|poster|720x360|hash',
            45,
        );
        $this->assertNotNull($other);

        $first->release();
        $replacement = $gate->acquireVariant(
            'media|poster|480x240|hash',
            45,
        );
        $this->assertNotNull($replacement);

        $replacement->release();
        $other->release();
    }
}
