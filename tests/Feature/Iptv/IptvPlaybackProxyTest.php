<?php

namespace Tests\Feature\Iptv;

use App\Models\Iptv\Channel;
use App\Models\Iptv\ChannelFavorite;
use App\Models\Iptv\EpgProgram;
use App\Models\Iptv\IptvPlaybackResource;
use App\Models\Iptv\IptvPlaybackSession;
use App\Models\User;
use App\Services\Iptv\HostAddressResolver;
use App\Services\Iptv\PlaybackConcurrencyGate;
use App\Services\Iptv\PlaybackResourceRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Cache;
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
        Cache::flush();
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
            ->assertSee('data-player-controls', escape: false)
            ->assertSee('data-player-channel-rail', escape: false)
            ->assertSee('data-player-rail-toggle', escape: false)
            ->assertSee('aria-label="Open channel list"', escape: false)
            ->assertSee('data-player-ambient', escape: false)
            ->assertSee('width="48"', escape: false)
            ->assertSee('height="27"', escape: false)
            ->assertSee(route('iptv.channels.icon', $channel))
            ->assertSee('data-stream-health', escape: false)
            ->assertSee('Resolution')
            ->assertSee('Bitrate')
            ->assertSee('data-stream-fps', escape: false)
            ->assertSee('FPS')
            ->assertSee('player-page')
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

    public function test_player_favorite_rail_contains_two_hours_of_guide_information(): void
    {
        $user = User::factory()->create([
            'is_active' => true,
            'timezone' => 'Europe/Zurich',
        ]);
        $current = $this->makeChannel($this->makeProvider(), [
            'name' => 'Current News',
        ]);
        $next = $this->makeChannel(
            $this->makeProvider(['name' => 'Second Provider']),
            ['name' => 'Favorite Sports'],
        );
        foreach ([$current, $next] as $channel) {
            ChannelFavorite::query()->create([
                'user_id' => $user->id,
                'channel_id' => $channel->id,
            ]);
        }
        EpgProgram::query()->create([
            'iptv_provider_id' => $next->iptv_provider_id,
            'channel_id' => $next->id,
            'fingerprint' => hash('sha256', 'favorite-sports-now'),
            'title' => 'Live Match',
            'starts_at' => now()->subMinutes(10),
            'ends_at' => now()->addMinutes(35),
        ]);
        EpgProgram::query()->create([
            'iptv_provider_id' => $next->iptv_provider_id,
            'channel_id' => $next->id,
            'fingerprint' => hash('sha256', 'favorite-sports-now-duplicate'),
            'title' => 'Live Match',
            'starts_at' => EpgProgram::query()
                ->where('fingerprint', hash('sha256', 'favorite-sports-now'))
                ->value('starts_at'),
            'ends_at' => now()->addMinutes(40),
        ]);
        EpgProgram::query()->create([
            'iptv_provider_id' => $next->iptv_provider_id,
            'channel_id' => $next->id,
            'fingerprint' => hash('sha256', 'favorite-sports-later'),
            'title' => 'Post-match Review',
            'starts_at' => now()->addMinutes(35),
            'ends_at' => now()->addMinutes(95),
        ]);
        EpgProgram::query()->create([
            'iptv_provider_id' => $next->iptv_provider_id,
            'channel_id' => $next->id,
            'fingerprint' => hash('sha256', 'favorite-sports-too-late'),
            'title' => 'Tomorrow Preview',
            'starts_at' => now()->addHours(3),
            'ends_at' => now()->addHours(4),
        ]);

        $this->actingAs($user)
            ->post(route('iptv.playback.store', $current))
            ->assertRedirect();
        $session = IptvPlaybackSession::query()->sole();

        $page = $this->actingAs($user)
            ->get(route('iptv.playback.show', $session))
            ->assertOk()
            ->assertSee('data-active-channel-id="'.$current->id.'"', escape: false)
            ->assertSee('data-favorite-channel', escape: false)
            ->assertSee('aria-current="true"', escape: false)
            ->assertSee('Current News')
            ->assertSee('Favorite Sports')
            ->assertSee('Live Match')
            ->assertSee('Post-match Review')
            ->assertDontSee('Tomorrow Preview')
            ->assertSee(route('iptv.channels.icon', $next))
            ->assertDontSee('images.example.test');
        $this->assertSame(1, substr_count($page->getContent(), 'Live Match'));
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
        $requestedAddresses = [];

        Http::fake(function (ClientRequest $request) use (
            &$requestedHosts,
            &$requestedAddresses,
        ) {
            $host = $request->header('Host')[0] ?? '';
            $requestedHosts[] = $host;
            $requestedAddresses[] = (string) parse_url(
                $request->url(),
                PHP_URL_HOST,
            );

            if ($host === 'iptv.example.test') {
                return Http::response('', 302, [
                    'Location' => 'http://stream.example.test/live/redirected/101',
                ]);
            }

            return Http::response(implode("\n", [
                '#EXTM3U',
                '#EXTINF:6.0,',
                '/hls/segment.ts',
                '#EXT-X-ENDLIST',
            ]), 200, [
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
        $this->assertSame(['8.8.8.8', '8.8.8.8'], $requestedAddresses);
        $root = IptvPlaybackResource::query()
            ->whereNull('parent_resource_id')
            ->sole();
        $segment = IptvPlaybackResource::query()
            ->where('resource_type', 'segment')
            ->sole();
        $this->assertSame(
            'http://stream.example.test/live/redirected/101',
            $root->upstream_url,
        );
        $this->assertSame(
            'http://stream.example.test/hls/segment.ts',
            $segment->upstream_url,
        );
        $this->assertStringNotContainsString(
            'stream.example.test',
            (string) DB::table('iptv_playback_resources')
                ->where('id', $root->id)
                ->value('upstream_url'),
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
        $this->actingAs($user)
            ->delete(route('iptv.playback.destroy', $session))
            ->assertRedirect();

        config()->set('iptv.manifest_max_resources', 10);
        config()->set('iptv.playback_max_resources', 2);
        Http::fake(fn () => Http::response(implode("\n", [
            '#EXTM3U',
            'one.ts',
            'two.ts',
        ]), 200, ['Content-Type' => 'application/x-mpegurl']));

        $this->actingAs($user)->post(route('iptv.playback.store', $channel));
        $secondSession = IptvPlaybackSession::query()
            ->whereKeyNot($session->id)
            ->sole();

        $this->actingAs($user)
            ->get(route('iptv.playback.manifest', $secondSession))
            ->assertStatus(502);
        $this->assertSame(2, $secondSession->resources()->count());
        $this->assertDatabaseHas('iptv_playback_attempts', [
            'iptv_playback_session_id' => $secondSession->id,
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

    public function test_ll_hls_rendition_reports_are_rewritten_with_generic_content_types(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $provider = $this->makeProvider([
            'username' => 'rendition-private-user',
            'password' => 'rendition-private-password',
        ]);
        $channel = $this->makeChannel($provider);

        Http::fake(function (ClientRequest $request) {
            $path = (string) parse_url($request->url(), PHP_URL_PATH);

            if (str_ends_with($path, '/101.m3u8')) {
                return Http::response(implode("\n", [
                    '#EXTM3U',
                    '#EXT-X-RENDITION-REPORT:URI="renditions/alternate",LAST-MSN=10',
                ]), 200, [
                    'Content-Type' => 'application/vnd.apple.mpegurl',
                ]);
            }

            return Http::response(implode("\n", [
                '#EXTM3U',
                '#EXTINF:6.0,',
                'https://cdn.example.test/live/rendition-private-user/rendition-private-password/one.ts',
            ]), 200, [
                'Content-Type' => 'application/octet-stream',
            ]);
        });

        $this->actingAs($user)->post(route('iptv.playback.store', $channel));
        $session = IptvPlaybackSession::query()->sole();
        $this->actingAs($user)
            ->get(route('iptv.playback.manifest', $session))
            ->assertOk();
        $rendition = $session->resources()
            ->where('resource_type', 'playlist')
            ->whereNotNull('parent_resource_id')
            ->sole();

        $response = $this->actingAs($user)
            ->get(route('iptv.playback.resource', [$session, $rendition]))
            ->assertOk();
        $body = (string) $response->getContent();

        $this->assertStringContainsString('/resources/', $body);
        $this->assertStringNotContainsString('cdn.example.test', $body);
        $this->assertStringNotContainsString('rendition-private-user', $body);
        $this->assertStringNotContainsString('rendition-private-password', $body);
    }

    public function test_generic_resources_are_sniffed_for_nested_hls_manifests(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $channel = $this->makeChannel($this->makeProvider());

        Http::fake(function (ClientRequest $request) {
            $path = (string) parse_url($request->url(), PHP_URL_PATH);

            if (str_ends_with($path, '/101.m3u8')) {
                return Http::response(implode("\n", [
                    '#EXTM3U',
                    '#EXT-X-PRELOAD-HINT:TYPE=PART,URI="dynamic/manifest"',
                ]), 200, [
                    'Content-Type' => 'application/vnd.apple.mpegurl',
                ]);
            }

            return Http::response(implode("\n", [
                '#EXTM3U',
                '#EXTINF:6.0,',
                'segment.ts',
            ]), 200, [
                'Content-Type' => 'text/plain',
            ]);
        });

        $this->actingAs($user)->post(route('iptv.playback.store', $channel));
        $session = IptvPlaybackSession::query()->sole();
        $this->actingAs($user)
            ->get(route('iptv.playback.manifest', $session))
            ->assertOk();
        $nested = $session->resources()
            ->where('resource_type', 'resource')
            ->sole();

        $response = $this->actingAs($user)
            ->get(route('iptv.playback.resource', [$session, $nested]))
            ->assertOk();

        $this->assertSame('playlist', $nested->fresh()->resource_type);
        $this->assertStringContainsString('/resources/', (string) $response->getContent());
    }

    public function test_optional_and_unknown_hls_metadata_is_stripped_without_exposing_tokens(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $channel = $this->makeChannel($this->makeProvider());
        Http::fake(fn () => Http::response(implode("\n", [
            '#EXTM3U',
            '#EXT-X-SESSION-DATA:DATA-ID="provider",VALUE="session-data-secret"',
            '#EXT-X-DATERANGE:ID="ad",X-PROVIDER-TOKEN="daterange-secret"',
            '#VENDOR-COMMENT:comment-secret',
            '#EXT-X-UNKNOWN:TOKEN="extension-secret"',
            '#EXT-X-TARGETDURATION:6',
            '#EXTINF:6.0,',
            'segment.ts',
        ]), 200, ['Content-Type' => 'application/vnd.apple.mpegurl']));

        $this->actingAs($user)->post(route('iptv.playback.store', $channel));
        $session = IptvPlaybackSession::query()->sole();
        $response = $this->actingAs($user)
            ->get(route('iptv.playback.manifest', $session))
            ->assertOk();
        $manifest = (string) $response->getContent();

        $this->assertStringContainsString('#EXT-X-TARGETDURATION:6', $manifest);
        $this->assertStringContainsString('/resources/', $manifest);

        foreach ([
            'session-data-secret',
            'daterange-secret',
            'comment-secret',
            'extension-secret',
        ] as $secret) {
            $this->assertStringNotContainsString($secret, $manifest);
        }
    }

    public function test_hls_variable_definitions_and_references_are_rejected(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $channel = $this->makeChannel($this->makeProvider());
        Http::fake(fn () => Http::response(implode("\n", [
            '#EXTM3U',
            '#EXT-X-DEFINE:NAME="TOKEN",VALUE="variable-provider-secret"',
            '#EXTINF:6.0,',
            'segments/{$TOKEN}.ts',
        ]), 200, ['Content-Type' => 'application/vnd.apple.mpegurl']));

        $this->actingAs($user)->post(route('iptv.playback.store', $channel));
        $session = IptvPlaybackSession::query()->sole();

        $this->actingAs($user)
            ->get(route('iptv.playback.manifest', $session))
            ->assertStatus(502)
            ->assertDontSee('variable-provider-secret')
            ->assertDontSee('TOKEN');
        $this->assertDatabaseHas('iptv_playback_attempts', [
            'iptv_playback_session_id' => $session->id,
            'outcome' => 'failed',
            'error_code' => 'invalid_hls_manifest',
        ]);
    }

    public function test_known_provider_credentials_cannot_survive_in_allowed_hls_metadata(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $provider = $this->makeProvider([
            'username' => 'provider-user-secret',
            'password' => 'provider-password-secret',
        ]);
        $channel = $this->makeChannel($provider);
        Http::fake(fn () => Http::response(implode("\n", [
            '#EXTM3U',
            '#EXT-X-MEDIA:TYPE=AUDIO,GROUP-ID="audio",NAME="provider-password-secret",URI="audio/index.m3u8"',
            '#EXT-X-STREAM-INF:BANDWIDTH=100000,AUDIO="audio"',
            'video/index.m3u8',
        ]), 200, ['Content-Type' => 'application/vnd.apple.mpegurl']));

        $this->actingAs($user)->post(route('iptv.playback.store', $channel));
        $session = IptvPlaybackSession::query()->sole();

        $this->actingAs($user)
            ->get(route('iptv.playback.manifest', $session))
            ->assertStatus(502)
            ->assertDontSee('provider-password-secret')
            ->assertDontSee('provider-user-secret');
        $this->assertDatabaseHas('iptv_playback_attempts', [
            'iptv_playback_session_id' => $session->id,
            'outcome' => 'failed',
            'error_code' => 'invalid_hls_manifest',
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

    public function test_sessions_are_reused_replaced_and_explicitly_released_without_monopolizing_provider_slots(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $otherUser = User::factory()->create(['is_active' => true]);
        $provider = $this->makeProvider([
            'config' => [
                'api' => 'xtream',
                'stream_format' => 'hls',
                'max_connections' => 1,
            ],
        ]);
        $firstChannel = $this->makeChannel($provider);
        $secondChannel = Channel::query()->create([
            'iptv_provider_id' => $provider->id,
            'channel_group_id' => $firstChannel->channel_group_id,
            'external_id' => '102',
            'epg_channel_id' => 'news.102',
            'name' => 'Example News Two',
            'channel_number' => '2',
            'stream_extension' => 'm3u8',
            'metadata' => [],
            'is_active' => true,
        ]);

        $this->actingAs($user)
            ->post(route('iptv.playback.store', $firstChannel))
            ->assertRedirect();
        $firstSession = IptvPlaybackSession::query()->sole();

        $this->actingAs($user)
            ->post(route('iptv.playback.store', $firstChannel))
            ->assertRedirect(route('iptv.playback.show', $firstSession));
        $this->assertDatabaseCount('iptv_playback_sessions', 1);

        $this->actingAs($user)
            ->post(route('iptv.playback.store', $secondChannel))
            ->assertRedirect();
        $secondSession = IptvPlaybackSession::query()
            ->whereKeyNot($firstSession->id)
            ->sole();
        $this->assertSame('released', $firstSession->fresh()->status);
        $this->assertTrue($firstSession->fresh()->expires_at->isPast());
        $this->assertSame('created', $secondSession->status);

        $this->actingAs($otherUser)
            ->delete(route('iptv.playback.destroy', $secondSession))
            ->assertNotFound();
        $this->assertSame('created', $secondSession->fresh()->status);

        $this->actingAs($user)
            ->delete(route('iptv.playback.destroy', $secondSession))
            ->assertRedirect(route('iptv.channels.index'));
        $this->assertSame('released', $secondSession->fresh()->status);
        $this->assertTrue($secondSession->fresh()->expires_at->isPast());
        $this->assertTrue(
            $secondSession->resources()->sole()->expires_at->isPast(),
        );

        $this->actingAs($otherUser)
            ->post(route('iptv.playback.store', $firstChannel))
            ->assertRedirect();
        $this->assertSame(
            'created',
            IptvPlaybackSession::query()
                ->where('user_id', $otherUser->id)
                ->sole()
                ->status,
        );
    }

    public function test_repeated_failed_playback_releases_the_provider_slot_at_the_threshold(): void
    {
        config()->set('iptv.playback_failure_threshold', 3);
        $user = User::factory()->create(['is_active' => true]);
        $otherUser = User::factory()->create(['is_active' => true]);
        $provider = $this->makeProvider([
            'config' => [
                'api' => 'xtream',
                'stream_format' => 'hls',
                'max_connections' => 1,
            ],
        ]);
        $channel = $this->makeChannel($provider);
        Http::fake(fn () => Http::response('', 503));

        $this->actingAs($user)->post(route('iptv.playback.store', $channel));
        $failedSession = IptvPlaybackSession::query()->sole();
        $this->actingAs($user)
            ->get(route('iptv.playback.manifest', $failedSession))
            ->assertStatus(502);

        $failedSession->refresh();
        $this->assertSame('created', $failedSession->status);
        $this->assertFalse($failedSession->expires_at->isPast());

        $this->actingAs($user)
            ->get(route('iptv.playback.manifest', $failedSession))
            ->assertStatus(502);
        $this->assertSame('created', $failedSession->fresh()->status);

        $this->actingAs($user)
            ->get(route('iptv.playback.manifest', $failedSession))
            ->assertStatus(502);

        $failedSession->refresh();
        $this->assertSame('failed', $failedSession->status);
        $this->assertSame(3, $failedSession->consecutive_failure_count);
        $this->assertTrue($failedSession->expires_at->isPast());
        $this->assertTrue($failedSession->resources()->sole()->expires_at->isPast());

        $this->actingAs($otherUser)
            ->post(route('iptv.playback.store', $channel))
            ->assertRedirect();
        $this->assertDatabaseHas('iptv_playback_sessions', [
            'user_id' => $otherUser->id,
            'status' => 'created',
        ]);
    }

    public function test_resource_failures_are_diagnostic_and_do_not_expire_the_session(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $channel = $this->makeChannel($this->makeProvider());
        $this->actingAs($user)->post(route('iptv.playback.store', $channel));
        $session = IptvPlaybackSession::query()->sole();
        $root = $session->resources()->sole();
        $segment = app(PlaybackResourceRepository::class)->create(
            $session,
            'https://iptv.example.test/live/segment.ts',
            'segment',
            $root,
        );
        Http::fake(fn () => Http::response('', 503));

        foreach (range(1, 5) as $_attempt) {
            $this->actingAs($user)
                ->get(route('iptv.playback.resource', [$session, $segment]))
                ->assertStatus(502);
        }

        $session->refresh();
        $this->assertSame('created', $session->status);
        $this->assertSame(0, $session->consecutive_failure_count);
        $this->assertFalse($session->expires_at->isPast());
        $this->assertDatabaseCount('iptv_playback_attempts', 5);
    }

    public function test_successful_manifest_resets_the_consecutive_failure_counter(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $channel = $this->makeChannel($this->makeProvider());
        $requests = 0;
        Http::fake(function () use (&$requests) {
            $requests++;

            return $requests === 1
                ? Http::response('', 503)
                : Http::response(
                    "#EXTM3U\n#EXT-X-ENDLIST",
                    200,
                    ['Content-Type' => 'application/vnd.apple.mpegurl'],
                );
        });

        $this->actingAs($user)->post(route('iptv.playback.store', $channel));
        $session = IptvPlaybackSession::query()->sole();

        $this->actingAs($user)
            ->get(route('iptv.playback.manifest', $session))
            ->assertStatus(502);
        $this->assertSame(1, $session->fresh()->consecutive_failure_count);

        $this->actingAs($user)
            ->get(route('iptv.playback.manifest', $session))
            ->assertOk();
        $session->refresh();
        $this->assertSame('playing', $session->status);
        $this->assertSame(0, $session->consecutive_failure_count);
        $this->assertNull($session->last_failure_at);
    }

    public function test_client_diagnostics_and_session_restart_are_owned_and_bounded(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $otherUser = User::factory()->create(['is_active' => true]);
        $provider = $this->makeProvider([
            'config' => [
                'api' => 'xtream',
                'stream_format' => 'hls',
                'max_connections' => 1,
            ],
        ]);
        $channel = $this->makeChannel($provider);
        $this->actingAs($user)->post(route('iptv.playback.store', $channel));
        $session = IptvPlaybackSession::query()->sole();

        $this->actingAs($otherUser)
            ->postJson(route('iptv.playback.diagnostics', $session), [
                'error_code' => 'no_decoded_frames',
            ])
            ->assertNotFound();

        $this->actingAs($user)
            ->postJson(route('iptv.playback.diagnostics', $session), [
                'error_code' => 'no_decoded_frames',
            ])
            ->assertNoContent();
        $this->assertSame('created', $session->fresh()->status);
        $this->assertDatabaseHas('iptv_playback_attempts', [
            'iptv_playback_session_id' => $session->id,
            'outcome' => 'failed',
            'error_code' => 'client_no_decoded_frames',
        ]);

        $response = $this->actingAs($user)
            ->postJson(route('iptv.playback.restart', $session))
            ->assertCreated()
            ->assertJsonStructure([
                'manifest_url',
                'restart_url',
                'diagnostic_url',
            ]);

        $replacement = IptvPlaybackSession::query()
            ->whereKeyNot($session->id)
            ->sole();
        $this->assertSame('released', $session->fresh()->status);
        $this->assertSame('created', $replacement->status);
        $this->assertSame(
            route('iptv.playback.manifest', $replacement),
            $response->json('manifest_url'),
        );
    }

    public function test_stale_created_sessions_do_not_hold_provider_slots(): void
    {
        config()->set('iptv.playback_lease_seconds', 30);
        $firstUser = User::factory()->create(['is_active' => true]);
        $secondUser = User::factory()->create(['is_active' => true]);
        $provider = $this->makeProvider([
            'config' => [
                'api' => 'xtream',
                'stream_format' => 'hls',
                'max_connections' => 1,
            ],
        ]);
        $channel = $this->makeChannel($provider);

        $this->actingAs($firstUser)
            ->post(route('iptv.playback.store', $channel));
        $stale = IptvPlaybackSession::query()->sole();
        $stale->forceFill([
            'created_at' => now()->subMinute(),
            'expires_at' => now()->addHour(),
        ])->save();
        $stale->resources()->update(['expires_at' => now()->addHour()]);

        $this->actingAs($secondUser)
            ->post(route('iptv.playback.store', $channel))
            ->assertRedirect();

        $this->assertSame('released', $stale->fresh()->status);
        $this->assertTrue($stale->fresh()->expires_at->isPast());
        $this->assertTrue($stale->resources()->sole()->expires_at->isPast());
        $this->assertDatabaseHas('iptv_playback_sessions', [
            'user_id' => $secondUser->id,
            'status' => 'created',
        ]);
    }

    public function test_successful_requests_renew_the_short_session_and_resource_lease(): void
    {
        config()->set('iptv.playback_lease_seconds', 30);
        $user = User::factory()->create(['is_active' => true]);
        $channel = $this->makeChannel($this->makeProvider());
        Http::fake(fn () => Http::response(
            "#EXTM3U\n#EXT-X-ENDLIST",
            200,
            ['Content-Type' => 'application/vnd.apple.mpegurl'],
        ));

        $this->actingAs($user)->post(route('iptv.playback.store', $channel));
        $session = IptvPlaybackSession::query()->sole();
        $session->forceFill([
            'last_accessed_at' => now()->subMinute(),
            'expires_at' => now()->addSecond(),
        ])->save();
        $session->resources()->update([
            'expires_at' => now()->addSecond(),
        ]);

        $this->actingAs($user)
            ->get(route('iptv.playback.manifest', $session))
            ->assertOk();

        $session->refresh();
        $this->assertSame('playing', $session->status);
        $this->assertTrue($session->expires_at->isAfter(now()->addSeconds(20)));
        $this->assertTrue(
            $session->resources()->sole()->expires_at->isAfter(
                now()->addSeconds(20),
            ),
        );
    }

    public function test_concurrent_playback_requests_are_bounded_per_session_and_provider(): void
    {
        config()->set('iptv.playback_session_concurrency', 2);
        config()->set('iptv.playback_provider_concurrency', 4);
        $firstUser = User::factory()->create(['is_active' => true]);
        $secondUser = User::factory()->create(['is_active' => true]);
        $provider = $this->makeProvider([
            'config' => [
                'api' => 'xtream',
                'stream_format' => 'hls',
                'max_connections' => 2,
            ],
        ]);
        $channel = $this->makeChannel($provider);

        $this->actingAs($firstUser)
            ->post(route('iptv.playback.store', $channel));
        $this->actingAs($secondUser)
            ->post(route('iptv.playback.store', $channel));
        $firstSession = IptvPlaybackSession::query()
            ->where('user_id', $firstUser->id)
            ->sole();
        $secondSession = IptvPlaybackSession::query()
            ->where('user_id', $secondUser->id)
            ->sole();
        $gate = app(PlaybackConcurrencyGate::class);

        $first = $gate->acquire($firstSession);
        $second = $gate->acquire($firstSession);
        $this->assertNotNull($first);
        $this->assertNotNull($second);
        $this->assertNull($gate->acquire($firstSession));
        $first->release();
        $second->release();

        $leases = [
            $gate->acquire($firstSession),
            $gate->acquire($secondSession),
            $gate->acquire($firstSession),
            $gate->acquire($secondSession),
        ];
        $this->assertNotContains(null, $leases);
        $this->assertNull($gate->acquire($firstSession));

        $this->actingAs($firstUser)
            ->get(route('iptv.playback.manifest', $firstSession))
            ->assertStatus(429)
            ->assertHeader('Retry-After', '1');
        Http::assertNothingSent();

        $leases[0]->release();
        Http::fake(fn () => Http::response(
            "#EXTM3U\n#EXT-X-ENDLIST",
            200,
            ['Content-Type' => 'application/vnd.apple.mpegurl'],
        ));
        $this->actingAs($firstUser)
            ->get(route('iptv.playback.manifest', $firstSession))
            ->assertOk();

        foreach ($leases as $lease) {
            $lease->release();
        }
    }

    public function test_stream_completion_releases_its_concurrency_slots(): void
    {
        config()->set('iptv.playback_session_concurrency', 2);
        config()->set('iptv.playback_provider_concurrency', 2);
        $user = User::factory()->create(['is_active' => true]);
        $provider = $this->makeProvider([
            'config' => [
                'api' => 'xtream',
                'stream_format' => 'hls',
                'max_connections' => 1,
            ],
        ]);
        $channel = $this->makeChannel($provider);
        $this->actingAs($user)->post(route('iptv.playback.store', $channel));
        $session = IptvPlaybackSession::query()->sole();
        $root = $session->resources()->sole();
        $segment = app(PlaybackResourceRepository::class)->create(
            $session,
            'https://iptv.example.test/live/segment.ts',
            'segment',
            $root,
        );
        Http::fake(fn () => Http::response(
            'bounded-video-segment',
            200,
            [
                'Content-Type' => 'video/mp2t',
                'Content-Length' => '21',
            ],
        ));
        $gate = app(PlaybackConcurrencyGate::class);
        $held = $gate->acquire($session);
        $this->assertNotNull($held);

        $response = $this->actingAs($user)
            ->get(route('iptv.playback.resource', [$session, $segment]))
            ->assertOk()
            ->assertStreamed();
        $this->assertNull($gate->acquire($session));
        $response->assertStreamedContent('bounded-video-segment');

        $afterCompletion = $gate->acquire($session);
        $this->assertNotNull($afterCompletion);
        $afterCompletion->release();
        $held->release();
    }
}
