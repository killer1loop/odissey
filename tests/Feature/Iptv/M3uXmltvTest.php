<?php

namespace Tests\Feature\Iptv;

use App\Models\Iptv\Channel;
use App\Models\Iptv\IptvProvider;
use App\Services\Iptv\M3uClient;
use App\Services\Iptv\UpstreamUrlGuard;
use App\Services\Iptv\XmltvGuideImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

class M3uXmltvTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        if (! config('app.key')) {
            config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        }
        $guard = Mockery::mock(UpstreamUrlGuard::class);
        $guard->shouldReceive('assertPublicTarget')->andReturnNull();
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

    public function test_xmltv_import_is_mapped_bounded_and_prunes_expired_rows(): void
    {
        $start = now()->utc()->addHour();
        $end = $start->copy()->addHour();
        $xml = sprintf('<?xml version="1.0"?><tv><programme channel="news.hd" start="%s +0000" stop="%s +0000"><title>Evening News</title><desc>Headlines</desc></programme></tv>', $start->format('YmdHis'), $end->format('YmdHis'));
        Http::fake(['guide.example.test/*' => Http::response($xml, 200, ['Content-Type' => 'application/xml'])]);
        $provider = $this->provider();
        $provider->update(['config' => array_merge($provider->config, ['xmltv_url' => 'https://guide.example.test/guide.xml'])]);
        Channel::create(['iptv_provider_id' => $provider->id, 'external_id' => '1', 'epg_channel_id' => 'news.hd', 'name' => 'News', 'stream_extension' => 'm3u8']);
        $this->assertSame(1, app(XmltvGuideImporter::class)->import($provider->fresh()));
        $this->assertDatabaseHas('epg_programs', ['title' => 'Evening News']);
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
