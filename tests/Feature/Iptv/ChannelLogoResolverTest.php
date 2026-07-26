<?php

namespace Tests\Feature\Iptv;

use App\Models\User;
use App\Services\Iptv\ChannelLogoResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\InteractsWithIptv;
use Tests\TestCase;

class ChannelLogoResolverTest extends TestCase
{
    use InteractsWithIptv;
    use RefreshDatabase;

    private string $externalLogo = 'https://logos.example.test/example-news.png';

    protected function setUp(): void
    {
        parent::setUp();
        $this->allowPublicIptvDns();
        Http::preventStrayRequests();
        config([
            'iptv.channel_logo_catalog_enabled' => true,
            'iptv.channel_logo_channels_url' => 'https://catalog.example.test/channels.json',
            'iptv.channel_logo_logos_url' => 'https://catalog.example.test/logos.json',
        ]);
    }

    public function test_catalog_matches_exact_epg_ids_and_conservative_name_variants(): void
    {
        $this->fakeCatalog();

        $resolution = app(ChannelLogoResolver::class)->resolve([
            [
                'stream_id' => '101',
                'epg_channel_id' => 'ExampleNews.us',
                'name' => 'Provider title is wrong',
                'stream_icon' => 'https://provider.example.test/wrong.png',
            ],
            [
                'stream_id' => '102',
                'epg_channel_id' => null,
                'name' => 'US: Example News HD',
                'stream_icon' => 'https://provider.example.test/wrong-2.png',
            ],
            [
                'stream_id' => '103',
                'epg_channel_id' => null,
                'name' => 'Unknown Channel',
                'stream_icon' => 'https://provider.example.test/wrong-3.png',
            ],
            [
                'stream_id' => '104',
                'epg_channel_id' => null,
                'name' => 'Example News HD',
            ],
        ], forceRefresh: true);

        $this->assertTrue($resolution->available);
        $this->assertSame(
            [
                'url' => $this->externalLogo,
                'channel_id' => 'ExampleNews.us',
            ],
            $resolution->match('101'),
        );
        $this->assertSame(
            $this->externalLogo,
            $resolution->match('102')['url'],
        );
        $this->assertNull($resolution->match('103'));
        $this->assertNull($resolution->match('104'));
    }

    public function test_refresh_replaces_existing_provider_artwork_and_icon_proxy_uses_only_external_match(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $provider = $this->makeProvider();
        $channel = $this->makeChannel($provider, [
            'epg_channel_id' => 'ExampleNews.us',
            'stream_icon' => 'https://provider.example.test/wrong.png',
            'logo_source' => null,
            'logo_channel_id' => null,
        ]);
        $this->fakeCatalog();

        $this->artisan('iptv:logos:refresh')
            ->expectsOutput('Matched 1 channel logo(s); 0 channel(s) use initials.')
            ->assertSuccessful();

        $channel->refresh();
        $this->assertSame($this->externalLogo, $channel->stream_icon);
        $this->assertSame('iptv-org', $channel->logo_source);
        $this->assertSame('ExampleNews.us', $channel->logo_channel_id);
        $raw = DB::table('channels')->where('id', $channel->id)->first();
        $this->assertStringNotContainsString(
            'logos.example.test',
            (string) $raw->stream_icon,
        );
        $this->assertStringNotContainsString(
            'provider.example.test',
            (string) $raw->stream_icon,
        );

        $png = base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=',
            strict: true,
        );
        $this->actingAs($user)
            ->get(route('iptv.channels.icon', $channel))
            ->assertOk()
            ->assertHeader('Content-Type', 'image/png')
            ->assertContent($png);

        $channel->forceFill([
            'stream_icon' => 'https://provider.example.test/wrong.png',
            'logo_source' => null,
            'logo_channel_id' => null,
        ])->save();
        $this->actingAs($user)
            ->get(route('iptv.channels.icon', $channel))
            ->assertNotFound();
    }

    private function fakeCatalog(): void
    {
        Http::fake(function (Request $request) {
            return match (parse_url($request->url(), PHP_URL_PATH)) {
                '/logos.json' => Http::response([
                    [
                        'channel' => 'ExampleNews.us',
                        'feed' => null,
                        'in_use' => true,
                        'tags' => ['horizontal', 'transparent'],
                        'width' => 512,
                        'height' => 180,
                        'format' => 'PNG',
                        'url' => $this->externalLogo,
                    ],
                    [
                        'channel' => 'ExampleNews.us',
                        'feed' => null,
                        'in_use' => true,
                        'tags' => ['horizontal'],
                        'width' => 2048,
                        'height' => 720,
                        'format' => 'SVG',
                        'url' => 'https://logos.example.test/unsafe.svg',
                    ],
                    [
                        'channel' => 'ExampleNews.ca',
                        'feed' => null,
                        'in_use' => true,
                        'tags' => ['horizontal', 'transparent'],
                        'width' => 512,
                        'height' => 180,
                        'format' => 'PNG',
                        'url' => 'https://logos.example.test/example-news-ca.png',
                    ],
                ]),
                '/channels.json' => Http::response([
                    [
                        'id' => 'ExampleNews.us',
                        'name' => 'Example News',
                        'alt_names' => ['Example News Network'],
                        'country' => 'US',
                        'is_nsfw' => false,
                        'closed' => null,
                    ],
                    [
                        'id' => 'ExampleNews.ca',
                        'name' => 'Example News',
                        'alt_names' => [],
                        'country' => 'CA',
                        'is_nsfw' => false,
                        'closed' => null,
                    ],
                ]),
                '/example-news.png' => Http::response(
                    base64_decode(
                        'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=',
                        strict: true,
                    ),
                    200,
                    ['Content-Type' => 'image/png'],
                ),
                default => Http::response([], 404),
            };
        });
    }
}
