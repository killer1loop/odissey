<?php

namespace Tests\Feature\Iptv;

use App\Models\Iptv\IptvPlaybackResource;
use App\Models\Iptv\IptvPlaybackSession;
use App\Models\User;
use App\Services\Iptv\HostAddressResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\InteractsWithIptv;
use Tests\TestCase;

class IptvPlaybackProxyTest extends TestCase
{
    use InteractsWithIptv;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->loadIptvRoutes();
        $this->allowPublicIptvDns();
        Http::preventStrayRequests();
    }

    public function test_live_hls_manifests_and_resources_are_recursively_rewritten_to_owned_opaque_tokens(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $otherUser = User::factory()->create(['is_active' => true]);
        $provider = $this->makeProvider([
            'base_url' => 'http://iptv.example.test',
            'username' => 'private-live-user',
            'password' => 'private-live-password',
            'allow_insecure_http' => true,
        ]);
        $channel = $this->makeChannel($provider);
        $ranges = [];

        Http::fake(function (ClientRequest $request) use (&$ranges) {
            $path = (string) parse_url($request->url(), PHP_URL_PATH);

            if (str_ends_with($path, '/101.m3u8')) {
                return Http::response(implode("\n", [
                    '#EXTM3U',
                    '#EXT-X-KEY:METHOD=AES-128,URI="keys/live.key"',
                    '#EXT-X-STREAM-INF:BANDWIDTH=1200000',
                    'variants/index.m3u8',
                ]), 200, ['Content-Type' => 'application/x-mpegurl']);
            }

            if (str_ends_with($path, '/variants/index.m3u8')) {
                return Http::response(implode("\n", [
                    '#EXTM3U',
                    '#EXT-X-TARGETDURATION:6',
                    '#EXTINF:6.0,',
                    '../segments/001.ts',
                ]), 200, ['Content-Type' => 'application/vnd.apple.mpegurl']);
            }

            if (str_ends_with($path, '/segments/001.ts')) {
                $ranges[] = $request->header('Range')[0] ?? null;

                return Http::response('binary-video-segment', 206, [
                    'Content-Type' => 'video/mp2t',
                    'Content-Length' => '20',
                    'Content-Range' => 'bytes 0-19/20',
                ]);
            }

            if (str_ends_with($path, '/keys/live.key')) {
                return Http::response('sixteen-byte-key!', 200, [
                    'Content-Type' => 'application/octet-stream',
                ]);
            }

            return Http::response('', 404);
        });

        $create = $this->actingAs($user)
            ->post(route('iptv.playback.store', $channel))
            ->assertRedirect();
        $session = IptvPlaybackSession::query()->sole();
        $this->assertSame(route('iptv.playback.show', $session), $create->headers->get('Location'));

        $page = $this->actingAs($user)
            ->get(route('iptv.playback.show', $session))
            ->assertOk()
            ->assertSee(route('iptv.playback.manifest', $session))
            ->assertDontSee('iptv.example.test')
            ->assertDontSee('private-live-user')
            ->assertDontSee('private-live-password');

        $manifest = $this->actingAs($user)
            ->get(route('iptv.playback.manifest', $session))
            ->assertOk();
        $masterBody = (string) $manifest->getContent();
        $this->assertStringContainsString('/resources/', $masterBody);
        $this->assertStringNotContainsString('iptv.example.test', $masterBody);
        $this->assertStringNotContainsString('private-live-user', $masterBody);
        $this->assertStringNotContainsString('private-live-password', $masterBody);

        $nested = IptvPlaybackResource::query()
            ->where('resource_type', 'playlist')
            ->whereNotNull('parent_resource_id')
            ->sole();
        $nestedManifest = $this->actingAs($user)
            ->get(route('iptv.playback.resource', [$session, $nested]))
            ->assertOk();
        $nestedBody = (string) $nestedManifest->getContent();
        $this->assertStringContainsString('/resources/', $nestedBody);
        $this->assertStringNotContainsString('iptv.example.test', $nestedBody);
        $this->assertStringNotContainsString('private-live-user', $nestedBody);
        $this->assertStringNotContainsString('private-live-password', $nestedBody);

        $segment = IptvPlaybackResource::query()
            ->where('resource_type', 'segment')
            ->sole();
        $this->actingAs($user)
            ->withHeader('Range', 'bytes=0-19')
            ->get(route('iptv.playback.resource', [$session, $segment]))
            ->assertStatus(206)
            ->assertStreamed();
        $this->assertSame(['bytes=0-19'], $ranges);

        $this->actingAs($otherUser)
            ->get(route('iptv.playback.manifest', $session))
            ->assertNotFound();
        $this->actingAs($otherUser)
            ->get(route('iptv.playback.resource', [$session, $segment]))
            ->assertNotFound();

        foreach (DB::table('iptv_playback_resources')->pluck('upstream_url') as $encryptedUrl) {
            $this->assertStringNotContainsString('iptv.example.test', $encryptedUrl);
            $this->assertStringNotContainsString('private-live-user', $encryptedUrl);
            $this->assertStringNotContainsString('private-live-password', $encryptedUrl);
        }

        $this->assertDatabaseHas('iptv_playback_attempts', [
            'iptv_playback_session_id' => $session->id,
            'outcome' => 'started',
            'upstream_status' => 200,
            'error_code' => null,
        ]);
        $this->assertNotEmpty($page->getContent());
    }

    public function test_bounded_redirects_are_manually_revalidated_before_fetching(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $provider = $this->makeProvider([
            'base_url' => 'http://iptv.example.test',
            'allow_insecure_http' => true,
        ]);
        $channel = $this->makeChannel($provider);
        $requestedHosts = [];

        Http::fake(function (ClientRequest $request) use (&$requestedHosts) {
            $host = (string) parse_url($request->url(), PHP_URL_HOST);
            $requestedHosts[] = $host;

            if ($host === 'iptv.example.test') {
                return Http::response('', 302, [
                    'Location' => 'http://stream.example.test/live/redirected/101',
                ]);
            }

            return Http::response("#EXTM3U\n#EXT-X-ENDLIST", 200, [
                'Content-Type' => 'application/vnd.apple.mpegurl',
            ]);
        });

        $this->actingAs($user)->post(route('iptv.playback.store', $channel));
        $session = IptvPlaybackSession::query()->sole();

        $this->actingAs($user)
            ->get(route('iptv.playback.manifest', $session))
            ->assertOk()
            ->assertSee('#EXTM3U');

        $this->assertSame(
            ['iptv.example.test', 'stream.example.test'],
            $requestedHosts,
        );
        $this->assertDatabaseHas('iptv_playback_attempts', [
            'iptv_playback_session_id' => $session->id,
            'outcome' => 'started',
            'upstream_status' => 200,
        ]);
    }

    public function test_redirects_to_non_public_targets_are_rejected_before_contact(): void
    {
        $this->app->instance(HostAddressResolver::class, new class extends HostAddressResolver
        {
            public function resolve(string $host): array
            {
                return $host === '127.0.0.1'
                    ? ['127.0.0.1']
                    : ['8.8.8.8'];
            }
        });
        $user = User::factory()->create(['is_active' => true]);
        $provider = $this->makeProvider([
            'base_url' => 'http://iptv.example.test',
            'allow_insecure_http' => true,
        ]);
        $channel = $this->makeChannel($provider);

        Http::fake(fn () => Http::response('', 302, [
            'Location' => 'http://127.0.0.1/internal',
        ]));

        $this->actingAs($user)->post(route('iptv.playback.store', $channel));
        $session = IptvPlaybackSession::query()->sole();

        $this->actingAs($user)
            ->get(route('iptv.playback.manifest', $session))
            ->assertStatus(502);

        Http::assertSentCount(1);
        $this->assertDatabaseHas('iptv_playback_attempts', [
            'iptv_playback_session_id' => $session->id,
            'outcome' => 'failed',
            'error_code' => 'blocked_upstream_target',
        ]);
    }

    public function test_redirect_hops_are_strictly_bounded(): void
    {
        config()->set('iptv.playback_max_redirects', 1);
        $user = User::factory()->create(['is_active' => true]);
        $provider = $this->makeProvider([
            'base_url' => 'http://iptv.example.test',
            'allow_insecure_http' => true,
        ]);
        $channel = $this->makeChannel($provider);

        Http::fake(fn () => Http::response('', 302, [
            'Location' => 'http://stream.example.test/another-hop',
        ]));

        $this->actingAs($user)->post(route('iptv.playback.store', $channel));
        $session = IptvPlaybackSession::query()->sole();

        $this->actingAs($user)
            ->get(route('iptv.playback.manifest', $session))
            ->assertStatus(502);

        Http::assertSentCount(2);
        $this->assertDatabaseHas('iptv_playback_attempts', [
            'iptv_playback_session_id' => $session->id,
            'outcome' => 'failed',
            'error_code' => 'upstream_redirect_limit',
        ]);
    }

    public function test_invalid_range_is_rejected_without_contacting_upstream(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $provider = $this->makeProvider();
        $channel = $this->makeChannel($provider);

        $this->actingAs($user)->post(route('iptv.playback.store', $channel));
        $session = IptvPlaybackSession::query()->sole();
        $resource = IptvPlaybackResource::query()->sole();

        $this->actingAs($user)
            ->withHeader('Range', 'units=secret')
            ->get(route('iptv.playback.resource', [$session, $resource]))
            ->assertStatus(416)
            ->assertDontSee('iptv.example.test')
            ->assertDontSee('test-user-secret')
            ->assertDontSee('test-password-secret');

        Http::assertNothingSent();
    }

    public function test_malformed_manifest_uri_is_sanitized_without_leaking_connection_data(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $provider = $this->makeProvider([
            'username' => 'malformed-private-user',
            'password' => 'malformed-private-password',
        ]);
        $channel = $this->makeChannel($provider);

        Http::fake(fn () => Http::response(implode("\n", [
            '#EXTM3U',
            '#EXT-X-KEY:METHOD=AES-128,URI="http://[malformed-private-user:malformed-private-password"',
        ]), 200, ['Content-Type' => 'application/x-mpegurl']));

        $this->actingAs($user)->post(route('iptv.playback.store', $channel));
        $session = IptvPlaybackSession::query()->sole();

        $this->actingAs($user)
            ->get(route('iptv.playback.manifest', $session))
            ->assertStatus(502)
            ->assertSee('temporarily unavailable')
            ->assertDontSee('malformed-private-user')
            ->assertDontSee('malformed-private-password')
            ->assertDontSee('iptv.example.test');

        $this->assertDatabaseHas('iptv_playback_attempts', [
            'iptv_playback_session_id' => $session->id,
            'outcome' => 'failed',
            'error_code' => 'invalid_hls_resource',
        ]);
    }

    public function test_manifest_and_session_resource_limits_bound_database_writes(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $channel = $this->makeChannel($this->makeProvider());

        config()->set('iptv.manifest_max_resources', 2);
        Http::fake(fn () => Http::response(implode("\n", [
            '#EXTM3U',
            'one.ts',
            'two.ts',
            'three.ts',
        ]), 200, ['Content-Type' => 'application/x-mpegurl']));

        $this->actingAs($user)->post(route('iptv.playback.store', $channel));
        $session = IptvPlaybackSession::query()->sole();

        $this->actingAs($user)
            ->get(route('iptv.playback.manifest', $session))
            ->assertStatus(502);
        $this->assertSame(1, $session->resources()->count());
        $this->assertDatabaseHas('iptv_playback_attempts', [
            'iptv_playback_session_id' => $session->id,
            'error_code' => 'manifest_resource_limit',
        ]);

        config()->set('iptv.manifest_max_resources', 10);
        config()->set('iptv.playback_max_resources', 2);
        Http::fake(fn () => Http::response(implode("\n", [
            '#EXTM3U',
            'one.ts',
            'two.ts',
        ]), 200, ['Content-Type' => 'application/x-mpegurl']));

        $this->actingAs($user)
            ->get(route('iptv.playback.manifest', $session))
            ->assertStatus(502);
        $this->assertSame(2, $session->resources()->count());
        $this->assertDatabaseHas('iptv_playback_attempts', [
            'iptv_playback_session_id' => $session->id,
            'error_code' => 'playback_resource_limit',
        ]);
    }

    public function test_nested_playlist_depth_is_bounded(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $channel = $this->makeChannel($this->makeProvider());
        config()->set('iptv.playlist_max_depth', 1);

        Http::fake(function (ClientRequest $request) {
            $path = (string) parse_url($request->url(), PHP_URL_PATH);

            if (str_ends_with($path, '/101.m3u8')) {
                return Http::response("#EXTM3U\n#EXT-X-STREAM-INF:BANDWIDTH=1\nlevel-one.m3u8");
            }

            return Http::response("#EXTM3U\n#EXT-X-STREAM-INF:BANDWIDTH=1\nlevel-two.m3u8");
        });

        $this->actingAs($user)->post(route('iptv.playback.store', $channel));
        $session = IptvPlaybackSession::query()->sole();
        $this->actingAs($user)
            ->get(route('iptv.playback.manifest', $session))
            ->assertOk();
        $levelOne = $session->resources()
            ->where('resource_type', 'playlist')
            ->where('depth', 1)
            ->sole();

        $this->actingAs($user)
            ->get(route('iptv.playback.resource', [$session, $levelOne]))
            ->assertStatus(502);
        $this->assertSame(2, $session->resources()->count());
        $this->assertDatabaseHas('iptv_playback_attempts', [
            'iptv_playback_session_id' => $session->id,
            'error_code' => 'playlist_depth_limit',
        ]);
    }

    public function test_disabling_or_reconfiguring_a_source_invalidates_existing_sessions(): void
    {
        $admin = User::factory()->create(['is_active' => true, 'is_admin' => true]);
        $provider = $this->makeProvider();
        $channel = $this->makeChannel($provider);
        Http::preventStrayRequests();

        $this->actingAs($admin)->post(route('iptv.playback.store', $channel));
        $session = IptvPlaybackSession::query()->sole();

        $this->actingAs($admin)
            ->put(route('iptv.admin.providers.update', $provider), [
                'name' => $provider->name,
                'username' => 'replacement-user',
                'password' => 'replacement-password',
                'enabled' => '1',
            ])
            ->assertRedirect(route('iptv.admin.providers.index'));

        $this->assertSame('invalidated', $session->fresh()->status);
        $this->actingAs($admin)
            ->get(route('iptv.playback.manifest', $session))
            ->assertStatus(410);
        Http::assertNothingSent();

        $this->actingAs($admin)->post(route('iptv.playback.store', $channel));
        $secondSession = IptvPlaybackSession::query()
            ->whereKeyNot($session->id)
            ->sole();
        $channel->update(['is_active' => false]);

        $this->actingAs($admin)
            ->get(route('iptv.playback.manifest', $secondSession))
            ->assertStatus(410);
        $this->assertSame('invalidated', $secondSession->fresh()->status);
        Http::assertNothingSent();
    }
}
