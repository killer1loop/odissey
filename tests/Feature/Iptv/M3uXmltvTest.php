<?php

namespace Tests\Feature\Iptv;

use App\Models\Iptv\Channel;
use App\Models\Iptv\EpgProgram;
use App\Models\Iptv\IptvProvider;
use App\Services\Iptv\BoundedResponseSink;
use App\Services\Iptv\ConfidentialHttpFactory;
use App\Services\Iptv\Exceptions\SanitizedIptvException;
use App\Services\Iptv\M3uClient;
use App\Services\Iptv\PinnedUpstreamTarget;
use App\Services\Iptv\UpstreamUrlGuard;
use App\Services\Iptv\XmltvGuideImporter;
use GuzzleHttp\Handler\CurlHandler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Events\RequestSending;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use LengthException;
use Mockery;
use ReflectionProperty;
use Tests\TestCase;

class M3uXmltvTest extends TestCase
{
    use RefreshDatabase;

    private InspectableConfidentialHttpFactory $http;

    protected function setUp(): void
    {
        parent::setUp();
        if (! config('app.key')) {
            config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        }
        $this->http = new InspectableConfidentialHttpFactory;
        $this->http->preventStrayRequests();
        Http::swap($this->http);
        $this->app->instance(ConfidentialHttpFactory::class, $this->http);

        $guard = Mockery::mock(UpstreamUrlGuard::class);
        $guard->shouldReceive('pin')->andReturnUsing(
            static function (string $url): PinnedUpstreamTarget {
                $parts = parse_url($url);

                return new PinnedUpstreamTarget(
                    url: $url,
                    host: (string) ($parts['host'] ?? ''),
                    port: (int) ($parts['port'] ?? 443),
                    address: '8.8.8.8',
                );
            },
        );
        $this->app->instance(UpstreamUrlGuard::class, $guard);
    }

    public function test_generic_m3u_catalog_preserves_groups_epg_ids_and_secret_stream_urls(): void
    {
        Http::fake(['playlist.example.test/*' => Http::response(<<<'M3U'
#EXTM3U
#EXTINF:-1 tvg-id="news.hd" tvg-logo="https://images.example.test/news.png" group-title="News",News HD
https://streams.example.test/live/news.m3u8
M3U)]);
        $provider = $this->provider();
        [$groups, $streams] = app(M3uClient::class)->catalog($provider);
        $this->assertSame('News', $groups[0]['category_name']);
        $this->assertSame('news.hd', $streams[0]['epg_channel_id']);
        $this->assertSame('https://streams.example.test/live/news.m3u8', $streams[0]['stream_url']);
    }

    public function test_generic_m3u_channel_limit_is_hard_clamped(): void
    {
        config()->set('iptv.playlist_max_channels', 1);
        Http::fake(['playlist.example.test/*' => Http::response(<<<'M3U'
#EXTM3U
#EXTINF:-1,First
https://streams.example.test/live/first.m3u8
#EXTINF:-1,Second
https://streams.example.test/live/second.m3u8
M3U)]);

        [, $streams] = app(M3uClient::class)->catalog($this->provider());

        $this->assertCount(1, $streams);
        $this->assertSame('First', $streams[0]['name']);
    }

    public function test_import_transport_is_dns_pinned_and_never_dispatches_credential_urls(): void
    {
        $secret = 'playlist-query-secret';
        $provider = $this->provider();
        $provider->update([
            'config' => array_merge($provider->config, [
                'playlist_url' => "https://playlist.example.test/channels.m3u?token={$secret}",
            ]),
        ]);
        $events = 0;
        Event::listen(RequestSending::class, function () use (&$events): void {
            $events++;
        });
        Http::fake([
            'playlist.example.test/*' => Http::response(
                "#EXTM3U\n#EXTINF:-1,News\nhttps://streams.example.test/news.m3u8",
            ),
        ]);

        app(M3uClient::class)->catalog($provider->fresh());

        $this->assertSame(0, $events);
        $options = $this->http->lastRequest?->getOptions() ?? [];
        $this->assertFalse($options['allow_redirects']);
        $this->assertTrue($options['decode_content']);
        $this->assertInstanceOf(BoundedResponseSink::class, $options['sink']);
        $this->assertSame(
            ['playlist.example.test:443:8.8.8.8'],
            $options['curl'][CURLOPT_RESOLVE],
        );
        $this->assertSame('', $options['curl'][CURLOPT_PROXY]);
        $handler = (new ReflectionProperty(PendingRequest::class, 'handler'))
            ->getValue($this->http->lastRequest);
        $this->assertInstanceOf(CurlHandler::class, $handler);
    }

    public function test_transport_exceptions_are_sanitized_without_url_credentials(): void
    {
        $secret = 'connection-exception-secret';
        $provider = $this->provider();
        $provider->update([
            'config' => array_merge($provider->config, [
                'playlist_url' => "https://playlist.example.test/channels.m3u?token={$secret}",
            ]),
        ]);
        Http::fake(static fn () => throw new ConnectionException(
            "Connection failed for playlist URL containing {$secret}.",
        ));

        try {
            app(M3uClient::class)->catalog($provider->fresh());
            $this->fail('Transport failures must be sanitized.');
        } catch (SanitizedIptvException $exception) {
            $this->assertSame('playlist_unavailable', $exception->errorCode);
            $this->assertStringNotContainsString($secret, $exception->getMessage());
            $this->assertStringNotContainsString('playlist.example.test', $exception->getMessage());
        }
    }

    public function test_playlist_response_limit_is_enforced_during_writes(): void
    {
        config()->set('iptv.playlist_max_bytes', 16);
        Http::fake([
            'playlist.example.test/*' => Http::response(
                "#EXTM3U\n".str_repeat('x', 32),
            ),
        ]);

        try {
            app(M3uClient::class)->catalog($this->provider());
            $this->fail('Oversized playlists must be rejected.');
        } catch (SanitizedIptvException $exception) {
            $this->assertSame('playlist_invalid', $exception->errorCode);
        }

        $sink = new BoundedResponseSink(4);
        $this->assertSame(4, $sink->write('1234'));
        $this->expectException(LengthException::class);
        $sink->write('5');
    }

    public function test_document_hard_limits_cannot_be_raised_by_environment_configuration(): void
    {
        config()->set('iptv.playlist_max_bytes', PHP_INT_MAX);
        config()->set('iptv.xmltv_max_bytes', PHP_INT_MAX);
        $provider = $this->provider();
        Http::fake([
            'playlist.example.test/*' => Http::response(
                "#EXTM3U\n#EXTINF:-1,News\nhttps://streams.example.test/news.m3u8",
                200,
                ['Content-Length' => (string) ((8 * 1024 * 1024) + 1)],
            ),
            'guide.example.test/*' => Http::response(
                '<tv></tv>',
                200,
                ['Content-Length' => (string) ((8 * 1024 * 1024) + 1)],
            ),
        ]);

        try {
            app(M3uClient::class)->catalog($provider);
            $this->fail('The playlist hard limit must not be configurable away.');
        } catch (SanitizedIptvException $exception) {
            $this->assertSame('playlist_invalid', $exception->errorCode);
        }

        $provider->update([
            'config' => array_merge($provider->config, [
                'xmltv_url' => 'https://guide.example.test/guide.xml',
            ]),
        ]);

        try {
            app(XmltvGuideImporter::class)->import($provider->fresh());
            $this->fail('The XMLTV hard limit must not be configurable away.');
        } catch (SanitizedIptvException $exception) {
            $this->assertSame('xmltv_invalid', $exception->errorCode);
        }
    }

    public function test_xmltv_response_limit_is_enforced_before_parsing(): void
    {
        config()->set('iptv.xmltv_max_bytes', 32);
        Http::fake([
            'guide.example.test/*' => Http::response(
                '<tv>'.str_repeat('x', 64).'</tv>',
            ),
        ]);
        $provider = $this->provider();
        $provider->update([
            'config' => array_merge($provider->config, [
                'xmltv_url' => 'https://guide.example.test/guide.xml',
            ]),
        ]);

        try {
            app(XmltvGuideImporter::class)->import($provider->fresh());
            $this->fail('Oversized XMLTV responses must be rejected.');
        } catch (SanitizedIptvException $exception) {
            $this->assertSame('xmltv_invalid', $exception->errorCode);
        }
    }

    public function test_xmltv_doctype_and_oversized_program_nodes_are_rejected(): void
    {
        $provider = $this->provider();
        $provider->update([
            'config' => array_merge($provider->config, [
                'xmltv_url' => 'https://guide.example.test/guide.xml',
            ]),
        ]);
        $channel = Channel::query()->create([
            'iptv_provider_id' => $provider->id,
            'external_id' => '1',
            'epg_channel_id' => 'news',
            'name' => 'News',
        ]);
        $start = now()->utc()->addHour();
        $end = $start->copy()->addHour();

        foreach ([
            sprintf(
                '<?xml version="1.0"?><!DOCTYPE tv [<!ENTITY xxe SYSTEM "file:///etc/passwd">]><tv><programme channel="news" start="%s +0000" stop="%s +0000"><title>&xxe;</title></programme></tv>',
                $start->format('YmdHis'),
                $end->format('YmdHis'),
            ),
            sprintf(
                '<tv><programme channel="news" start="%s +0000" stop="%s +0000"><title>News</title><desc>%s</desc></programme></tv>',
                $start->format('YmdHis'),
                $end->format('YmdHis'),
                str_repeat('x', 257 * 1024),
            ),
        ] as $xml) {
            Http::fake([
                'guide.example.test/*' => Http::response(
                    $xml,
                    200,
                    ['Content-Type' => 'application/xml'],
                ),
            ]);

            try {
                app(XmltvGuideImporter::class)->import($provider->fresh());
                $this->fail('Unsafe XMLTV input must be rejected.');
            } catch (SanitizedIptvException $exception) {
                $this->assertSame('xmltv_invalid', $exception->errorCode);
            }

            $this->assertDatabaseMissing('epg_programs', [
                'channel_id' => $channel->id,
            ]);
        }
    }

    public function test_xmltv_import_is_mapped_bounded_and_prunes_expired_rows(): void
    {
        $start = now()->utc()->addHour();
        $end = $start->copy()->addHour();
        $xml = sprintf('<?xml version="1.0"?><tv><programme channel="news.hd" start="%s +0000" stop="%s +0000"><title>Evening News</title><desc>Headlines</desc></programme></tv>', $start->format('YmdHis'), $end->format('YmdHis'));
        Http::fake(['guide.example.test/*' => Http::response($xml, 200, ['Content-Type' => 'application/xml'])]);
        $provider = $this->provider();
        $provider->update(['config' => array_merge($provider->config, ['xmltv_url' => 'https://guide.example.test/guide.xml'])]);
        $channel = Channel::create(['iptv_provider_id' => $provider->id, 'external_id' => '1', 'epg_channel_id' => 'news.hd', 'name' => 'News', 'stream_extension' => 'm3u8']);
        $stale = EpgProgram::query()->create([
            'iptv_provider_id' => $provider->id,
            'channel_id' => $channel->id,
            'fingerprint' => hash('sha256', 'stale'),
            'title' => 'Stale future programme',
            'starts_at' => now()->addHours(3),
            'ends_at' => now()->addHours(4),
        ]);
        $expired = EpgProgram::query()->create([
            'iptv_provider_id' => $provider->id,
            'channel_id' => $channel->id,
            'fingerprint' => hash('sha256', 'expired'),
            'title' => 'Expired programme',
            'starts_at' => now()->subDays(3),
            'ends_at' => now()->subDays(2),
        ]);

        $this->assertSame(1, app(XmltvGuideImporter::class)->import($provider->fresh()));
        $this->assertDatabaseHas('epg_programs', ['title' => 'Evening News']);
        $this->assertDatabaseMissing('epg_programs', ['id' => $stale->id]);
        $this->assertDatabaseMissing('epg_programs', ['id' => $expired->id]);
        $this->assertNotNull(
            EpgProgram::query()->where('title', 'Evening News')->sole()->sync_token,
        );
    }

    public function test_xmltv_channel_program_and_provider_row_limits_bound_guide_storage(): void
    {
        config()->set('iptv.xmltv_max_channels', 1);
        config()->set('iptv.xmltv_max_programs', 3);
        config()->set('iptv.provider_guide_max_rows', 10);
        $provider = $this->provider();
        $provider->update([
            'config' => array_merge($provider->config, [
                'xmltv_url' => 'https://guide.example.test/guide.xml',
            ]),
        ]);
        Channel::query()->create([
            'iptv_provider_id' => $provider->id,
            'external_id' => '1',
            'epg_channel_id' => 'first',
            'name' => 'First',
        ]);
        Channel::query()->create([
            'iptv_provider_id' => $provider->id,
            'external_id' => '2',
            'epg_channel_id' => 'second',
            'name' => 'Second',
        ]);
        $start = now()->utc()->addHour();
        $programmes = '';

        foreach ([
            ['first', 'First One', 0],
            ['first', 'First Two', 1],
            ['second', 'Excluded channel', 2],
            ['first', 'Excluded by programme limit', 3],
        ] as [$channel, $title, $offset]) {
            $programStart = $start->copy()->addHours($offset);
            $programmes .= sprintf(
                '<programme channel="%s" start="%s +0000" stop="%s +0000"><title>%s</title></programme>',
                $channel,
                $programStart->format('YmdHis'),
                $programStart->copy()->addHour()->format('YmdHis'),
                $title,
            );
        }

        Http::fake([
            'guide.example.test/*' => Http::response(
                "<?xml version=\"1.0\"?><tv>{$programmes}</tv>",
                200,
                ['Content-Type' => 'application/xml'],
            ),
        ]);

        $this->assertSame(
            2,
            app(XmltvGuideImporter::class)->import($provider->fresh()),
        );
        $this->assertDatabaseCount('epg_programs', 2);
        $this->assertDatabaseMissing('epg_programs', [
            'title' => 'Excluded channel',
        ]);
        $this->assertDatabaseMissing('epg_programs', [
            'title' => 'Excluded by programme limit',
        ]);
    }

    public function test_invalid_xmltv_rolls_back_partial_rows_and_preserves_the_last_guide(): void
    {
        $provider = $this->provider();
        $provider->update([
            'config' => array_merge($provider->config, [
                'xmltv_url' => 'https://guide.example.test/guide.xml',
            ]),
        ]);
        $channel = Channel::query()->create([
            'iptv_provider_id' => $provider->id,
            'external_id' => '1',
            'epg_channel_id' => 'news.hd',
            'name' => 'News',
        ]);
        $preserved = EpgProgram::query()->create([
            'iptv_provider_id' => $provider->id,
            'channel_id' => $channel->id,
            'fingerprint' => hash('sha256', 'preserved'),
            'sync_token' => (string) Str::ulid(),
            'title' => 'Preserved guide',
            'starts_at' => now()->addHours(2),
            'ends_at' => now()->addHours(3),
        ]);
        $start = now()->utc()->addHour();
        $xml = sprintf(
            '<?xml version="1.0"?><tv><programme channel="news.hd" start="%s +0000" stop="%s +0000"><title>Partial row</title></programme><programme',
            $start->format('YmdHis'),
            $start->copy()->addHour()->format('YmdHis'),
        );
        Http::fake([
            'guide.example.test/*' => Http::response(
                $xml,
                200,
                ['Content-Type' => 'application/xml'],
            ),
        ]);

        try {
            app(XmltvGuideImporter::class)->import($provider->fresh());
            $this->fail('Malformed XMLTV must not commit partial guide rows.');
        } catch (SanitizedIptvException $exception) {
            $this->assertSame('xmltv_invalid', $exception->errorCode);
        }

        $this->assertDatabaseHas('epg_programs', ['id' => $preserved->id]);
        $this->assertDatabaseMissing('epg_programs', ['title' => 'Partial row']);
    }

    public function test_capped_xmltv_import_preserves_unseen_future_guide_rows(): void
    {
        config()->set('iptv.xmltv_max_programs', 1);
        config()->set('iptv.provider_guide_max_rows', 10);
        $provider = $this->provider();
        $provider->update([
            'config' => array_merge($provider->config, [
                'xmltv_url' => 'https://guide.example.test/guide.xml',
            ]),
        ]);
        $channel = Channel::query()->create([
            'iptv_provider_id' => $provider->id,
            'external_id' => '1',
            'epg_channel_id' => 'news',
            'name' => 'News',
        ]);
        $preserved = EpgProgram::query()->create([
            'iptv_provider_id' => $provider->id,
            'channel_id' => $channel->id,
            'fingerprint' => hash('sha256', 'unseen-future'),
            'sync_token' => (string) Str::ulid(),
            'title' => 'Unseen future programme',
            'starts_at' => now()->addHours(5),
            'ends_at' => now()->addHours(6),
        ]);
        $start = now()->utc()->addHour();
        $xml = sprintf(
            '<tv><programme channel="news" start="%s +0000" stop="%s +0000"><title>Imported first</title></programme><programme channel="news" start="%s +0000" stop="%s +0000"><title>Capped second</title></programme></tv>',
            $start->format('YmdHis'),
            $start->copy()->addHour()->format('YmdHis'),
            $start->copy()->addHours(2)->format('YmdHis'),
            $start->copy()->addHours(3)->format('YmdHis'),
        );
        Http::fake([
            'guide.example.test/*' => Http::response(
                $xml,
                200,
                ['Content-Type' => 'application/xml'],
            ),
        ]);

        $this->assertSame(
            1,
            app(XmltvGuideImporter::class)->import($provider->fresh()),
        );
        $this->assertDatabaseHas('epg_programs', ['id' => $preserved->id]);
        $this->assertDatabaseHas('epg_programs', ['title' => 'Imported first']);
        $this->assertDatabaseMissing('epg_programs', ['title' => 'Capped second']);
    }

    public function test_malformed_xmltv_tail_after_cap_rolls_back_and_preserves_last_guide(): void
    {
        config()->set('iptv.xmltv_max_programs', 1);
        $provider = $this->provider();
        $provider->update([
            'config' => array_merge($provider->config, [
                'xmltv_url' => 'https://guide.example.test/guide.xml',
            ]),
        ]);
        $channel = Channel::query()->create([
            'iptv_provider_id' => $provider->id,
            'external_id' => '1',
            'epg_channel_id' => 'news',
            'name' => 'News',
        ]);
        $preserved = EpgProgram::query()->create([
            'iptv_provider_id' => $provider->id,
            'channel_id' => $channel->id,
            'fingerprint' => hash('sha256', 'last-good-guide'),
            'sync_token' => (string) Str::ulid(),
            'title' => 'Last good guide',
            'starts_at' => now()->addHours(5),
            'ends_at' => now()->addHours(6),
        ]);
        $start = now()->utc()->addHour();
        $xml = sprintf(
            '<tv><programme channel="news" start="%s +0000" stop="%s +0000"><title>Would be partial</title></programme><programme channel="news" start="%s +0000" stop="%s +0000"><title>Malformed tail</title>',
            $start->format('YmdHis'),
            $start->copy()->addHour()->format('YmdHis'),
            $start->copy()->addHours(2)->format('YmdHis'),
            $start->copy()->addHours(3)->format('YmdHis'),
        );
        Http::fake([
            'guide.example.test/*' => Http::response(
                $xml,
                200,
                ['Content-Type' => 'application/xml'],
            ),
        ]);

        try {
            app(XmltvGuideImporter::class)->import($provider->fresh());
            $this->fail('Malformed XMLTV after the cap must invalidate the import.');
        } catch (SanitizedIptvException $exception) {
            $this->assertSame('xmltv_invalid', $exception->errorCode);
        }

        $this->assertDatabaseHas('epg_programs', ['id' => $preserved->id]);
        $this->assertDatabaseMissing('epg_programs', [
            'title' => 'Would be partial',
        ]);
    }

    private function provider(): IptvProvider
    {
        return IptvProvider::create([
            'name' => 'Generic', 'base_url' => 'https://playlist.example.test',
            'username' => '', 'password' => '',
            'config' => ['api' => 'm3u', 'playlist_url' => 'https://playlist.example.test/channels.m3u'],
            'enabled' => true,
        ]);
    }
}

class InspectableConfidentialHttpFactory extends ConfidentialHttpFactory
{
    public ?PendingRequest $lastRequest = null;

    public function createPendingRequest()
    {
        return $this->lastRequest = parent::createPendingRequest();
    }
}
