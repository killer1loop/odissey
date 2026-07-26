<?php

namespace Tests\Feature\Iptv;

use App\Models\Iptv\IptvPlaybackSession;
use App\Models\User;
use App\Services\Iptv\HostAddressResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\InteractsWithIptv;
use Tests\TestCase;

class IptvNetworkHardeningTest extends TestCase
{
    use InteractsWithIptv;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->loadIptvRoutes();
        $this->allowPublicIptvDns();
        Cache::flush();
        Http::preventStrayRequests();
    }

    public function test_stream_handler_uses_short_header_and_explicit_read_timeouts(): void
    {
        config()->set('iptv.connect_timeout_seconds', 3);
        config()->set('iptv.stream_timeout_seconds', 17);
        $user = User::factory()->create(['is_active' => true]);
        $channel = $this->makeChannel($this->makeProvider());
        $capturedOptions = null;

        Http::fake(function (
            ClientRequest $request,
            array $options,
        ) use (&$capturedOptions) {
            $capturedOptions = $options;

            return Http::response(
                "#EXTM3U\n#EXTINF:6.0,\nsegment.ts",
                200,
                ['Content-Type' => 'application/x-mpegurl'],
            );
        });

        $this->actingAs($user)->post(route('iptv.playback.store', $channel));
        $session = IptvPlaybackSession::query()->sole();

        $this->actingAs($user)
            ->get(route('iptv.playback.manifest', $session))
            ->assertOk();

        $this->assertIsArray($capturedOptions);
        $this->assertSame(3, $capturedOptions['connect_timeout']);
        $this->assertSame(3, $capturedOptions['timeout']);
        $this->assertSame(17, $capturedOptions['read_timeout']);
        $this->assertTrue($capturedOptions['stream']);
        $this->assertNull($capturedOptions['proxy']['https']);
    }

    public function test_manifest_reuses_dns_validation_for_each_normalized_origin(): void
    {
        $resolver = $this->useCountingResolver();
        $user = User::factory()->create(['is_active' => true]);
        $channel = $this->makeChannel($this->makeProvider());

        Http::fake(fn () => Http::response(implode("\n", [
            '#EXTM3U',
            '#EXTINF:6.0,',
            'https://cdn.example.test/one.ts',
            '#EXTINF:6.0,',
            'https://cdn.example.test/two.ts?token=two',
            '#EXT-X-MAP:URI="https://cdn.example.test/init.mp4"',
        ]), 200, ['Content-Type' => 'application/x-mpegurl']));

        $this->actingAs($user)->post(route('iptv.playback.store', $channel));
        $session = IptvPlaybackSession::query()->sole();
        $resolver->calls = [];

        $this->actingAs($user)
            ->get(route('iptv.playback.manifest', $session))
            ->assertOk();

        $this->assertSame(1, $resolver->calls['iptv.example.test'] ?? 0);
        $this->assertSame(1, $resolver->calls['cdn.example.test'] ?? 0);
    }

    public function test_manifest_strips_content_steering_urls(): void
    {
        $resolver = $this->useCountingResolver();
        $user = User::factory()->create(['is_active' => true]);
        $channel = $this->makeChannel($this->makeProvider());

        Http::fake(fn () => Http::response(implode("\n", [
            '#EXTM3U',
            '#EXT-X-CONTENT-STEERING:SERVER-URI="https://steering.example.test/config.json",PATHWAY-ID="cdn-a"',
            '#EXTINF:6.0,',
            'segment.ts',
        ]), 200, ['Content-Type' => 'application/x-mpegurl']));

        $this->actingAs($user)->post(route('iptv.playback.store', $channel));
        $session = IptvPlaybackSession::query()->sole();

        $manifest = $this->actingAs($user)
            ->get(route('iptv.playback.manifest', $session))
            ->assertOk()
            ->assertDontSee('#EXT-X-CONTENT-STEERING')
            ->assertDontSee('steering.example.test');

        $this->assertStringContainsString(
            '/resources/',
            (string) $manifest->getContent(),
        );
        $this->assertArrayNotHasKey(
            'steering.example.test',
            $resolver->calls,
        );
    }

    public function test_manifest_origin_limit_is_configurable(): void
    {
        config()->set('iptv.manifest_max_origins', 2);
        $resolver = $this->useCountingResolver();
        $user = User::factory()->create(['is_active' => true]);
        $channel = $this->makeChannel($this->makeProvider());

        Http::fake(fn () => Http::response(implode("\n", [
            '#EXTM3U',
            'https://one.example.test/segment.ts',
            'https://two.example.test/segment.ts',
            'https://three.example.test/segment.ts',
        ]), 200, ['Content-Type' => 'application/x-mpegurl']));

        $this->actingAs($user)->post(route('iptv.playback.store', $channel));
        $session = IptvPlaybackSession::query()->sole();

        $this->actingAs($user)
            ->get(route('iptv.playback.manifest', $session))
            ->assertStatus(502);

        $this->assertSame(1, $resolver->calls['one.example.test'] ?? 0);
        $this->assertSame(1, $resolver->calls['two.example.test'] ?? 0);
        $this->assertArrayNotHasKey('three.example.test', $resolver->calls);
        $this->assertDatabaseHas('iptv_playback_attempts', [
            'iptv_playback_session_id' => $session->id,
            'error_code' => 'manifest_origin_limit',
        ]);
    }

    public function test_manifest_origin_limit_has_a_non_configurable_hard_cap(): void
    {
        config()->set('iptv.manifest_max_origins', 100);
        $resolver = $this->useCountingResolver();
        $user = User::factory()->create(['is_active' => true]);
        $channel = $this->makeChannel($this->makeProvider());
        $resources = ['#EXTM3U'];

        for ($index = 1; $index <= 33; $index++) {
            $resources[] = "https://origin{$index}.example.test/segment.ts";
        }

        Http::fake(fn () => Http::response(
            implode("\n", $resources),
            200,
            ['Content-Type' => 'application/x-mpegurl'],
        ));

        $this->actingAs($user)->post(route('iptv.playback.store', $channel));
        $session = IptvPlaybackSession::query()->sole();

        $this->actingAs($user)
            ->get(route('iptv.playback.manifest', $session))
            ->assertStatus(502);

        $this->assertSame(32, collect($resolver->calls)
            ->except('iptv.example.test')
            ->count());
        $this->assertArrayNotHasKey(
            'origin33.example.test',
            $resolver->calls,
        );
        $this->assertSame(
            'manifest_origin_limit',
            DB::table('iptv_playback_attempts')
                ->where('iptv_playback_session_id', $session->id)
                ->value('error_code'),
        );
    }

    private function useCountingResolver(): CountingHostAddressResolver
    {
        $resolver = new CountingHostAddressResolver;
        $this->app->instance(HostAddressResolver::class, $resolver);

        return $resolver;
    }
}

class CountingHostAddressResolver extends HostAddressResolver
{
    /** @var array<string, int> */
    public array $calls = [];

    public function resolve(string $host): array
    {
        $this->calls[$host] = ($this->calls[$host] ?? 0) + 1;

        return ['8.8.8.8'];
    }
}
