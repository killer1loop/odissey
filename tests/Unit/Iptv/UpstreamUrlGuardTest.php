<?php

namespace Tests\Unit\Iptv;

use App\Services\Iptv\ConfidentialHttpFactory;
use App\Services\Iptv\Exceptions\SanitizedIptvException;
use App\Services\Iptv\HostAddressResolver;
use App\Services\Iptv\UpstreamUrlGuard;
use PHPUnit\Framework\TestCase;

class UpstreamUrlGuardTest extends TestCase
{
    public function test_confidential_transport_has_no_request_event_dispatcher(): void
    {
        $this->assertNull((new ConfidentialHttpFactory)->getDispatcher());
    }

    public function test_all_resolved_addresses_must_be_public(): void
    {
        $resolver = new class extends HostAddressResolver
        {
            public array $addresses = ['8.8.8.8'];

            public function resolve(string $host): array
            {
                return $this->addresses;
            }
        };
        $guard = new UpstreamUrlGuard($resolver);

        $target = $guard->pin('https://provider.example.test:8443/live', false);
        $this->assertSame('https://provider.example.test:8443/live', $target->url);
        $this->assertSame('provider.example.test', $target->host);
        $this->assertSame(8443, $target->port);
        $this->assertSame('8.8.8.8', $target->address);
        $this->assertSame(
            ['provider.example.test:8443:8.8.8.8'],
            $target->curlOptions()[CURLOPT_RESOLVE],
        );
        $this->assertSame('', $target->curlOptions()[CURLOPT_PROXY]);
        $this->assertSame('*', $target->curlOptions()[CURLOPT_NOPROXY]);

        $resolver->addresses = ['8.8.8.8', '127.0.0.1'];

        try {
            $guard->assertPublicTarget('https://cdn.example.test/live.m3u8', false);
            $this->fail('A mixed public/private DNS answer must be rejected.');
        } catch (SanitizedIptvException $exception) {
            $this->assertSame('blocked_upstream_target', $exception->errorCode);
            $this->assertStringNotContainsString('cdn.example.test', $exception->getMessage());
        }
    }

    public function test_http_needs_consent_and_unresolved_hosts_are_rejected(): void
    {
        $resolver = new class extends HostAddressResolver
        {
            public function resolve(string $host): array
            {
                return [];
            }
        };
        $guard = new UpstreamUrlGuard($resolver);

        try {
            $guard->normalizeBaseUrl('http://provider.example.test', false);
            $this->fail('HTTP without consent must be rejected.');
        } catch (SanitizedIptvException $exception) {
            $this->assertSame('insecure_http_requires_consent', $exception->errorCode);
        }

        try {
            $guard->normalizeBaseUrl('https://provider.example.test', false);
            $this->fail('An unresolved target must be rejected.');
        } catch (SanitizedIptvException $exception) {
            $this->assertSame('unresolved_upstream_target', $exception->errorCode);
        }
    }

    public function test_ambiguous_hosts_fragments_and_invalid_ports_are_rejected(): void
    {
        $resolver = new class extends HostAddressResolver
        {
            public function resolve(string $host): array
            {
                return ['8.8.8.8'];
            }
        };
        $guard = new UpstreamUrlGuard($resolver);

        foreach ([
            'https://provider.example.test./live',
            'https://provider.example.test/live#fragment',
            'https://provider.example.test:99999/live',
        ] as $url) {
            try {
                $guard->pin($url, false);
                $this->fail("Ambiguous upstream URL should fail: {$url}");
            } catch (SanitizedIptvException $exception) {
                $this->assertSame(
                    'invalid_upstream_resource',
                    $exception->errorCode,
                );
            }
        }
    }
}
