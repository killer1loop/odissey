<?php

namespace Tests\Concerns;

use App\Models\Iptv\Channel;
use App\Models\Iptv\ChannelGroup;
use App\Models\Iptv\IptvProvider;
use App\Services\Iptv\ConfidentialHttpFactory;
use App\Services\Iptv\HostAddressResolver;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Mockery\MockInterface;

trait InteractsWithIptv
{
    protected function loadIptvRoutes(): void
    {
        if (! Route::has('iptv.channels.index')) {
            Route::middleware('web')->group(base_path('routes/iptv.php'));
        }
    }

    protected function allowPublicIptvDns(): void
    {
        // Reuse Laravel's test HTTP factory so Http::fake() remains the only
        // transport used by IPTV tests. Production resolves the eventless
        // ConfidentialHttpFactory directly.
        $http = new ConfidentialHttpFactory;
        Http::swap($http);
        $this->app->instance(ConfidentialHttpFactory::class, $http);

        $this->mock(
            HostAddressResolver::class,
            fn (MockInterface $mock) => $mock
                ->shouldReceive('resolve')
                ->andReturn(['8.8.8.8']),
        );
    }

    protected function makeProvider(array $attributes = []): IptvProvider
    {
        return IptvProvider::query()->create([
            'name' => 'Test IPTV',
            'base_url' => 'https://iptv.example.test',
            'username' => 'test-user-secret',
            'password' => 'test-password-secret',
            'config' => ['api' => 'xtream', 'stream_format' => 'hls'],
            'allow_insecure_http' => false,
            'enabled' => true,
            ...$attributes,
        ]);
    }

    protected function makeChannel(
        IptvProvider $provider,
        array $attributes = [],
    ): Channel {
        $group = ChannelGroup::query()->create([
            'iptv_provider_id' => $provider->id,
            'external_id' => 'news',
            'name' => 'News',
            'sort_order' => 1,
            'is_active' => true,
        ]);

        return Channel::query()->create([
            'iptv_provider_id' => $provider->id,
            'channel_group_id' => $group->id,
            'external_id' => '101',
            'epg_channel_id' => 'news.101',
            'name' => 'Example News',
            'channel_number' => '1',
            'stream_icon' => 'https://images.example.test/news.png',
            'stream_extension' => 'm3u8',
            'metadata' => ['archive' => false],
            'is_active' => true,
            ...$attributes,
        ]);
    }
}
