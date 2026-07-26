<?php

namespace Tests\Feature\Media;

use App\Models\MediaSource;
use App\Services\Iptv\ConfidentialHttpFactory;
use App\Services\Media\Sources\HttpSourceGuard;
use App\Services\Media\Sources\PinnedSourceTarget;
use App\Services\Media\Sources\S3SourceAdapter;
use App\Services\Media\Sources\WebDavSourceAdapter;
use GuzzleHttp\Handler\StreamHandler;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\PendingRequest;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionProperty;
use RuntimeException;
use Tests\TestCase;

class RemoteSourceContractTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        if (! config('app.key')) {
            config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        }
    }

    public function test_s3_catalog_is_paginated_signed_and_range_capable(): void
    {
        $http = new InspectableMediaHttpFactory;
        $http->fake(['93.184.216.34/*' => $http->response('<?xml version="1.0"?><ListBucketResult><IsTruncated>false</IsTruncated><Contents><Key>Movies/Arrival.mp4</Key><LastModified>2026-01-01T00:00:00Z</LastModified><ETag>"etag"</ETag><Size>4</Size></Contents></ListBucketResult>')]);
        $source = MediaSource::create(['name' => 'S3', 'type' => 's3', 'configuration' => ['endpoint' => 'https://s3.example.test', 'bucket' => 'media', 'prefix' => 'Movies/', 'region' => 'us-east-1', 'access_key' => 'access', 'secret_key' => 'secret']]);
        $guard = Mockery::mock(HttpSourceGuard::class);
        $guard->shouldReceive('pin')->andReturn($this->target('https://s3.example.test'));
        $objects = iterator_to_array((new S3SourceAdapter($guard, $http))->objects($source));
        $this->assertSame('Movies/Arrival.mp4', $objects[0]->locator);
        $http->assertSent(function ($request): bool {
            return parse_url($request->url(), PHP_URL_HOST) === '93.184.216.34'
                && $request->hasHeader('Host', 's3.example.test')
                && str_starts_with(
                    $request->header('Authorization')[0] ?? '',
                    'AWS4-HMAC-SHA256 Credential=access/',
                );
        });
        $options = $http->lastRequest?->getOptions() ?? [];
        $this->assertArrayNotHasKey('curl', $options);
        $this->assertNull($options['proxy']['https']);
        $this->assertSame(10, $options['timeout']);
        $this->assertSame(60, $options['read_timeout']);
        $this->assertSame(
            's3.example.test',
            $options['stream_context']['ssl']['peer_name'],
        );
        $handler = (new ReflectionProperty(PendingRequest::class, 'handler'))
            ->getValue($http->lastRequest);
        $this->assertInstanceOf(StreamHandler::class, $handler);
    }

    public function test_webdav_catalog_and_range_probe_use_read_only_methods(): void
    {
        $http = new InspectableMediaHttpFactory;
        $xml = '<?xml version="1.0"?><d:multistatus xmlns:d="DAV:"><d:response><d:href>/videos/Movie.mp4</d:href><d:propstat><d:prop><d:getcontentlength>4</d:getcontentlength><d:getlastmodified>Wed, 01 Jan 2026 00:00:00 GMT</d:getlastmodified><d:getetag>"etag"</d:getetag><d:resourcetype/></d:prop></d:propstat></d:response></d:multistatus>';
        $probeBody = Utils::streamFor('x');
        $http->fake(fn ($request) => $request->method() === 'PROPFIND'
            ? $http->response($xml, 207)
            : $http->response($probeBody, 206, ['Content-Length' => '1', 'Content-Range' => 'bytes 0-0/4']));
        $source = MediaSource::create(['name' => 'DAV', 'type' => 'webdav', 'configuration' => ['url' => 'https://dav.example.test/videos', 'username' => 'reader', 'password' => 'secret']]);
        $guard = Mockery::mock(HttpSourceGuard::class);
        $guard->shouldReceive('pin')->andReturn($this->target('https://dav.example.test/videos'));
        $adapter = new WebDavSourceAdapter($guard, $http);
        $this->assertTrue($adapter->capabilities($source)['range']);
        $http->assertSent(fn ($request) => in_array($request->method(), ['PROPFIND', 'GET'], true));
        $options = $http->lastRequest?->getOptions() ?? [];
        $this->assertArrayNotHasKey('curl', $options);
        $this->assertNull($options['proxy']['https']);
        $this->assertSame(10, $options['timeout']);
        $this->assertSame(60, $options['read_timeout']);
        $this->assertSame(
            'dav.example.test',
            $options['stream_context']['ssl']['peer_name'],
        );
        $handler = (new ReflectionProperty(PendingRequest::class, 'handler'))
            ->getValue($http->lastRequest);
        $this->assertInstanceOf(StreamHandler::class, $handler);
        $this->assertFalse($probeBody->isReadable());
    }

    public function test_s3_upstream_416_is_exposed_as_a_safe_range_error(): void
    {
        $http = new ConfidentialHttpFactory;
        $http->fake([
            '93.184.216.34/*' => $http->response('', 416, [
                'Content-Range' => 'bytes */4',
            ]),
        ]);
        $guard = Mockery::mock(HttpSourceGuard::class);
        $guard->shouldReceive('pin')
            ->once()
            ->andReturn($this->target('https://s3.example.test'));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('source_range_invalid');

        (new S3SourceAdapter($guard, $http))->open(
            $this->s3Source(),
            'Movie.mp4',
            10,
            null,
        );
    }

    public function test_webdav_upstream_416_is_exposed_as_a_safe_range_error(): void
    {
        $http = new ConfidentialHttpFactory;
        $http->fake([
            '93.184.216.34/*' => $http->response('', 416, [
                'Content-Range' => 'bytes */4',
            ]),
        ]);
        $guard = Mockery::mock(HttpSourceGuard::class);
        $guard->shouldReceive('pin')
            ->once()
            ->andReturn($this->target('https://dav.example.test/videos'));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('source_range_invalid');

        (new WebDavSourceAdapter($guard, $http))->open(
            $this->webDavSource(),
            'Movie.mp4',
            10,
            null,
        );
    }

    public function test_s3_redirects_are_rejected_without_contacting_the_location(): void
    {
        $http = new ConfidentialHttpFactory;
        $http->fake([
            '93.184.216.34/*' => $http->response('', 302, ['Location' => 'http://127.0.0.1/latest/meta-data']),
            '127.0.0.1/*' => $http->response('secret'),
        ]);
        $source = MediaSource::create(['name' => 'S3 redirect', 'type' => 's3', 'configuration' => ['endpoint' => 'https://s3.example.test', 'bucket' => 'media', 'region' => 'us-east-1', 'access_key' => 'access', 'secret_key' => 'secret']]);
        $guard = Mockery::mock(HttpSourceGuard::class);
        $guard->shouldReceive('pin')->once()->andReturn($this->target('https://s3.example.test'));

        try {
            (new S3SourceAdapter($guard, $http))->capabilities($source);
            $this->fail('The redirect should have been rejected.');
        } catch (RuntimeException $exception) {
            $this->assertSame('s3_request_failed', $exception->getMessage());
        }

        $http->assertSentCount(1);
        $http->assertNotSent(fn ($request) => $request->url() === 'http://127.0.0.1/latest/meta-data');
    }

    public function test_webdav_redirects_are_rejected_without_forwarding_basic_credentials(): void
    {
        $http = new ConfidentialHttpFactory;
        $http->fake([
            '93.184.216.34/*' => $http->response('', 302, ['Location' => 'https://other.example.test/private']),
            'other.example.test/*' => $http->response('secret'),
        ]);
        $source = MediaSource::create(['name' => 'DAV redirect', 'type' => 'webdav', 'configuration' => ['url' => 'https://dav.example.test/videos', 'username' => 'reader', 'password' => 'secret']]);
        $guard = Mockery::mock(HttpSourceGuard::class);
        $guard->shouldReceive('pin')->once()->andReturn($this->target('https://dav.example.test/videos'));

        try {
            iterator_to_array((new WebDavSourceAdapter($guard, $http))->objects($source));
            $this->fail('The redirect should have been rejected.');
        } catch (RuntimeException $exception) {
            $this->assertSame('webdav_catalog_failed', $exception->getMessage());
        }

        $http->assertSentCount(1);
        $http->assertNotSent(fn ($request) => str_contains($request->url(), 'other.example.test'));
    }

    public function test_webdav_catalog_uses_bounded_depth_one_traversal(): void
    {
        $http = new ConfidentialHttpFactory;
        $root = $this->webDavCatalog([
            $this->webDavEntry('/videos/', true),
            $this->webDavEntry('/videos/Shows/', true),
            $this->webDavEntry('/videos/Root%20Movie.mp4'),
        ]);
        $shows = $this->webDavCatalog([
            $this->webDavEntry('/videos/Shows/', true),
            $this->webDavEntry('/videos/Shows/Episode%201.mp4'),
        ]);
        $http->fake(function ($request) use ($http, $root, $shows) {
            $this->assertSame('PROPFIND', $request->method());
            $this->assertSame('1', $request->header('Depth')[0] ?? null);

            return $http->response(
                str_ends_with($request->url(), '/Shows/') ? $shows : $root,
                207,
            );
        });
        $source = $this->webDavSource();
        $guard = Mockery::mock(HttpSourceGuard::class);
        $guard->shouldReceive('pin')->once()->andReturn($this->target('https://dav.example.test/videos'));

        $objects = iterator_to_array((new WebDavSourceAdapter($guard, $http))->objects($source));

        $this->assertSame(
            ['Root Movie.mp4', 'Shows/Episode 1.mp4'],
            array_map(fn ($object) => $object->locator, $objects),
        );
        $http->assertSentCount(2);
    }

    #[DataProvider('unsafeWebDavHrefProvider')]
    public function test_webdav_catalog_rejects_unsafe_href_references(string $href): void
    {
        $http = new ConfidentialHttpFactory;
        $http->fake([
            '93.184.216.34/*' => $http->response(
                $this->webDavCatalog([$this->webDavEntry($href)]),
                207,
            ),
        ]);
        $guard = Mockery::mock(HttpSourceGuard::class);
        $guard->shouldReceive('pin')->once()->andReturn($this->target('https://dav.example.test/videos'));

        try {
            iterator_to_array(
                (new WebDavSourceAdapter($guard, $http))->objects($this->webDavSource()),
            );
            $this->fail("The unsafe WebDAV href [{$href}] should have been rejected.");
        } catch (RuntimeException $exception) {
            $this->assertSame('webdav_href_invalid', $exception->getMessage());
        }

        $http->assertSentCount(1);
    }

    public static function unsafeWebDavHrefProvider(): array
    {
        return [
            'cross origin' => ['https://other.example.test/videos/Escape.mp4'],
            'outside collection' => ['/private/Escape.mp4'],
            'literal parent segment' => ['../Escape.mp4'],
            'encoded parent segment' => ['/videos/%2e%2e/Escape.mp4'],
            'double encoded parent segment' => ['/videos/%252e%252e/Escape.mp4'],
            'encoded slash' => ['/videos/Folder%2FEscape.mp4'],
            'double encoded backslash' => ['/videos/Folder%255CEscape.mp4'],
            'encoded control' => ['/videos/%00Escape.mp4'],
            'literal backslash' => ['/videos/Folder\\Escape.mp4'],
            'query component' => ['/videos/Movie.mp4?download=1'],
        ];
    }

    public function test_webdav_catalog_rejects_an_oversized_locator(): void
    {
        config(['odissey.source_catalog_max_locator_bytes' => 255]);
        $http = new ConfidentialHttpFactory;
        $http->fake([
            '93.184.216.34/*' => $http->response(
                $this->webDavCatalog([
                    $this->webDavEntry('/videos/'.str_repeat('a', 256).'.mp4'),
                ]),
                207,
            ),
        ]);
        $guard = Mockery::mock(HttpSourceGuard::class);
        $guard->shouldReceive('pin')->once()->andReturn($this->target('https://dav.example.test/videos'));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('source_catalog_locator_invalid');

        iterator_to_array(
            (new WebDavSourceAdapter($guard, $http))->objects($this->webDavSource()),
        );
    }

    #[DataProvider('unsafeWebDavLocatorProvider')]
    public function test_webdav_open_rejects_unsafe_persisted_locators(
        string $locator,
    ): void {
        config(['odissey.source_catalog_max_locator_bytes' => 255]);
        $http = new ConfidentialHttpFactory;
        $http->preventStrayRequests();
        $guard = Mockery::mock(HttpSourceGuard::class);
        $guard->shouldNotReceive('pin');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('source_read_failed');

        (new WebDavSourceAdapter($guard, $http))->open(
            $this->webDavSource(),
            $locator,
            null,
            null,
        );
    }

    public static function unsafeWebDavLocatorProvider(): array
    {
        return [
            'empty' => [''],
            'leading slash' => ['/private/Escape.mp4'],
            'leading backslash' => ['\\private\\Escape.mp4'],
            'literal parent segment' => ['../Escape.mp4'],
            'nested parent segment' => ['Folder/../Escape.mp4'],
            'encoded parent segment' => ['%2e%2e/Escape.mp4'],
            'double encoded parent segment' => ['%252e%252e/Escape.mp4'],
            'encoded slash' => ['Folder%2FEscape.mp4'],
            'literal backslash' => ['Folder\\Escape.mp4'],
            'control byte' => ["Folder/\0Escape.mp4"],
            'oversized' => [str_repeat('a', 256)],
        ];
    }

    public function test_s3_catalog_rejects_repeated_continuation_tokens(): void
    {
        $http = new ConfidentialHttpFactory;
        $http->fake(
            fn () => $http->response(
                '<?xml version="1.0"?><ListBucketResult><IsTruncated>true</IsTruncated><NextContinuationToken>same-token</NextContinuationToken></ListBucketResult>',
            ),
        );
        $guard = Mockery::mock(HttpSourceGuard::class);
        $guard->shouldReceive('pin')->once()->andReturn($this->target('https://s3.example.test'));

        try {
            iterator_to_array(
                (new S3SourceAdapter($guard, $http))->objects($this->s3Source()),
            );
            $this->fail('The repeated S3 continuation token should have been rejected.');
        } catch (RuntimeException $exception) {
            $this->assertSame('s3_pagination_cycle', $exception->getMessage());
        }

        $http->assertSentCount(2);
    }

    public function test_s3_independent_catalog_fetches_repin_the_origin(): void
    {
        $http = new ConfidentialHttpFactory;
        $http->fake(
            fn () => $http->response(
                '<?xml version="1.0"?><ListBucketResult><IsTruncated>false</IsTruncated></ListBucketResult>',
            ),
        );
        $guard = Mockery::mock(HttpSourceGuard::class);
        $guard->shouldReceive('pin')
            ->twice()
            ->andReturn($this->target('https://s3.example.test'));
        $adapter = new S3SourceAdapter($guard, $http);
        $source = $this->s3Source();

        $adapter->capabilities($source);
        $adapter->capabilities($source);

        $http->assertSentCount(2);
    }

    public function test_s3_catalog_stops_at_the_configured_page_limit(): void
    {
        config(['odissey.source_catalog_max_s3_pages' => 1]);
        $http = new ConfidentialHttpFactory;
        $http->fake([
            '93.184.216.34/*' => $http->response(
                '<?xml version="1.0"?><ListBucketResult><IsTruncated>true</IsTruncated><NextContinuationToken>next-token</NextContinuationToken></ListBucketResult>',
            ),
        ]);
        $guard = Mockery::mock(HttpSourceGuard::class);
        $guard->shouldReceive('pin')->once()->andReturn($this->target('https://s3.example.test'));

        try {
            iterator_to_array(
                (new S3SourceAdapter($guard, $http))->objects($this->s3Source()),
            );
            $this->fail('The S3 page limit should have stopped pagination.');
        } catch (RuntimeException $exception) {
            $this->assertSame('source_catalog_request_limit', $exception->getMessage());
        }

        $http->assertSentCount(1);
    }

    public function test_s3_catalog_stops_at_the_configured_object_limit(): void
    {
        config(['odissey.source_catalog_max_items' => 1]);
        $http = new ConfidentialHttpFactory;
        $http->fake([
            '93.184.216.34/*' => $http->response(
                '<?xml version="1.0"?><ListBucketResult><IsTruncated>false</IsTruncated><Contents><Key>one.mp4</Key><Size>1</Size></Contents><Contents><Key>two.mp4</Key><Size>1</Size></Contents></ListBucketResult>',
            ),
        ]);
        $guard = Mockery::mock(HttpSourceGuard::class);
        $guard->shouldReceive('pin')->once()->andReturn($this->target('https://s3.example.test'));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('source_catalog_item_limit');

        iterator_to_array(
            (new S3SourceAdapter($guard, $http))->objects($this->s3Source()),
        );
    }

    public function test_s3_catalog_stream_is_bounded_before_xml_parsing(): void
    {
        config(['odissey.source_catalog_max_bytes' => 1024]);
        $http = new ConfidentialHttpFactory;
        $http->fake([
            '93.184.216.34/*' => $http->response(str_repeat('x', 1025)),
        ]);
        $guard = Mockery::mock(HttpSourceGuard::class);
        $guard->shouldReceive('pin')->once()->andReturn($this->target('https://s3.example.test'));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('source_catalog_byte_limit');

        iterator_to_array(
            (new S3SourceAdapter($guard, $http))->objects($this->s3Source()),
        );
    }

    public function test_s3_catalog_rejects_doctype_before_xml_parsing(): void
    {
        $http = new ConfidentialHttpFactory;
        $http->fake([
            '93.184.216.34/*' => $http->response(
                '<?xml version="1.0"?><!DOCTYPE ListBucketResult [<!ENTITY xxe SYSTEM "file:///etc/passwd">]><ListBucketResult><IsTruncated>false</IsTruncated></ListBucketResult>',
            ),
        ]);
        $guard = Mockery::mock(HttpSourceGuard::class);
        $guard->shouldReceive('pin')->once()->andReturn($this->target('https://s3.example.test'));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('s3_catalog_invalid');

        iterator_to_array(
            (new S3SourceAdapter($guard, $http))->objects($this->s3Source()),
        );
    }

    private function s3Source(): MediaSource
    {
        return MediaSource::create([
            'name' => 'S3 '.fake()->uuid(),
            'type' => 's3',
            'configuration' => [
                'endpoint' => 'https://s3.example.test',
                'bucket' => 'media',
                'region' => 'us-east-1',
                'access_key' => 'access',
                'secret_key' => 'secret',
            ],
        ]);
    }

    private function webDavSource(): MediaSource
    {
        return MediaSource::create([
            'name' => 'DAV '.fake()->uuid(),
            'type' => 'webdav',
            'configuration' => [
                'url' => 'https://dav.example.test/videos',
                'username' => 'reader',
                'password' => 'secret',
            ],
        ]);
    }

    /**
     * @param  list<string>  $entries
     */
    private function webDavCatalog(array $entries): string
    {
        return '<?xml version="1.0"?><d:multistatus xmlns:d="DAV:">'
            .implode('', $entries)
            .'</d:multistatus>';
    }

    private function webDavEntry(string $href, bool $collection = false): string
    {
        return '<d:response><d:href>'.htmlspecialchars($href, ENT_XML1)
            .'</d:href><d:propstat><d:status>HTTP/1.1 200 OK</d:status><d:prop>'
            .'<d:getcontentlength>4</d:getcontentlength><d:getetag>"etag"</d:getetag>'
            .'<d:resourcetype>'.($collection ? '<d:collection/>' : '').'</d:resourcetype>'
            .'</d:prop></d:propstat></d:response>';
    }

    private function target(string $url): PinnedSourceTarget
    {
        $parts = parse_url($url);

        return new PinnedSourceTarget(
            url: $url,
            host: $parts['host'],
            port: $parts['port'] ?? 443,
            address: '93.184.216.34',
        );
    }
}

class InspectableMediaHttpFactory extends ConfidentialHttpFactory
{
    public ?PendingRequest $lastRequest = null;

    public function createPendingRequest()
    {
        return $this->lastRequest = parent::createPendingRequest();
    }
}
