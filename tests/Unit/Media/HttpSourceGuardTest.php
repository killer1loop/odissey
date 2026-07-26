<?php

namespace Tests\Unit\Media;

use App\Services\Iptv\HostAddressResolver;
use App\Services\Media\Sources\HttpSourceGuard;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class HttpSourceGuardTest extends TestCase
{
    public function test_public_hostname_is_pinned_and_inherited_proxies_are_disabled(): void
    {
        $resolver = Mockery::mock(HostAddressResolver::class);
        $resolver->shouldReceive('resolve')
            ->once()
            ->with('media.example.test')
            ->andReturn(['93.184.216.34']);

        $target = (new HttpSourceGuard($resolver))->pin(
            'https://media.example.test:8443/library',
            false,
        );
        $options = $target->curlOptions();

        $this->assertSame('media.example.test', $target->host);
        $this->assertSame(8443, $target->port);
        $this->assertSame(
            ['media.example.test:8443:93.184.216.34'],
            $options[CURLOPT_RESOLVE],
        );
        $this->assertSame('', $options[CURLOPT_PROXY]);
        $this->assertSame('*', $options[CURLOPT_NOPROXY]);
    }

    public function test_private_resolution_is_rejected_without_explicit_consent(): void
    {
        $resolver = Mockery::mock(HostAddressResolver::class);
        $resolver->shouldReceive('resolve')
            ->once()
            ->andReturn(['127.0.0.1']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('private_source_address');

        (new HttpSourceGuard($resolver))->pin(
            'https://media.example.test/library',
            false,
        );
    }

    public function test_private_resolution_can_be_pinned_after_explicit_consent(): void
    {
        $resolver = Mockery::mock(HostAddressResolver::class);
        $resolver->shouldReceive('resolve')
            ->once()
            ->andReturn(['10.0.0.25']);

        $target = (new HttpSourceGuard($resolver))->pin(
            'http://media.internal.test/library',
            true,
        );

        $this->assertSame('10.0.0.25', $target->address);
        $this->assertSame(
            ['media.internal.test:80:10.0.0.25'],
            $target->curlOptions()[CURLOPT_RESOLVE],
        );
    }
}
