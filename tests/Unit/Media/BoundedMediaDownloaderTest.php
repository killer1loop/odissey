<?php

namespace Tests\Unit\Media;

use App\Services\Iptv\ConfidentialHttpFactory;
use App\Services\Iptv\HostAddressResolver;
use App\Services\Media\BoundedMediaDownloader;
use App\Services\Media\Sources\HttpSourceGuard;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class BoundedMediaDownloaderTest extends TestCase
{
    public function test_redirect_hops_are_revalidated_pinned_and_drop_sensitive_headers_cross_origin(): void
    {
        $http = new ConfidentialHttpFactory;
        $http->fake([
            'https://image.tmdb.org/*' => $http->response('', 302, [
                'Location' => 'https://static.tvmaze.com/poster.jpg',
            ]),
            'https://static.tvmaze.com/*' => $http->response('jpeg-bytes', 200, [
                'Content-Type' => 'image/jpeg',
            ]),
        ]);
        $resolver = Mockery::mock(HostAddressResolver::class);
        $resolver->shouldReceive('resolve')
            ->once()
            ->with('image.tmdb.org')
            ->andReturn(['93.184.216.34']);
        $resolver->shouldReceive('resolve')
            ->once()
            ->with('static.tvmaze.com')
            ->andReturn(['93.184.216.35']);

        $result = $this->downloader($resolver, $http)->download(
            url: 'https://image.tmdb.org/poster.jpg',
            maxBytes: 32,
            allowedHost: fn (string $host): bool => in_array(
                $host,
                ['image.tmdb.org', 'static.tvmaze.com'],
                true,
            ),
            headers: [
                'Api-Key' => 'must-not-cross-origins',
                'Authorization' => 'Bearer must-not-cross-origins',
                'User-Agent' => 'Odissey test',
            ],
        );

        $this->assertSame('jpeg-bytes', $result['body']);
        $this->assertSame('image/jpeg', $result['content_type']);
        $http->assertSent(function ($request): bool {
            if (! str_contains($request->url(), 'static.tvmaze.com')) {
                return false;
            }

            return ! $request->hasHeader('Api-Key')
                && ! $request->hasHeader('Authorization')
                && $request->hasHeader('User-Agent', 'Odissey test');
        });
        $http->assertSentCount(2);
    }

    public function test_redirect_to_a_disallowed_host_is_rejected_before_dns_or_http(): void
    {
        $http = new ConfidentialHttpFactory;
        $http->fake([
            'https://image.tmdb.org/*' => $http->response('', 302, [
                'Location' => 'https://127.0.0.1/private',
            ]),
            '*' => $http->response('secret'),
        ]);
        $resolver = Mockery::mock(HostAddressResolver::class);
        $resolver->shouldReceive('resolve')
            ->once()
            ->with('image.tmdb.org')
            ->andReturn(['93.184.216.34']);

        try {
            $this->downloader($resolver, $http)->download(
                url: 'https://image.tmdb.org/poster.jpg',
                maxBytes: 32,
                allowedHost: fn (string $host): bool => $host === 'image.tmdb.org',
            );
            $this->fail('The disallowed redirect should have been rejected.');
        } catch (RuntimeException $exception) {
            $this->assertSame('remote_download_rejected', $exception->getMessage());
        }

        $http->assertSentCount(1);
    }

    public function test_response_body_is_stopped_at_the_incremental_byte_limit(): void
    {
        $http = new ConfidentialHttpFactory;
        $http->fake([
            'https://image.tmdb.org/*' => $http->response(
                '123456789',
                200,
                ['Content-Type' => 'image/jpeg'],
            ),
        ]);
        $resolver = Mockery::mock(HostAddressResolver::class);
        $resolver->shouldReceive('resolve')
            ->once()
            ->andReturn(['93.184.216.34']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('remote_download_too_large');

        $this->downloader($resolver, $http)->download(
            url: 'https://image.tmdb.org/poster.jpg',
            maxBytes: 8,
            allowedHost: fn (string $host): bool => $host === 'image.tmdb.org',
        );
    }

    private function downloader(
        HostAddressResolver $resolver,
        ConfidentialHttpFactory $http,
    ): BoundedMediaDownloader {
        return new BoundedMediaDownloader(
            new HttpSourceGuard($resolver),
            $http,
        );
    }
}
