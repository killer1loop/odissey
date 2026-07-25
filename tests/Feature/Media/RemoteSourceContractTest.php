<?php

namespace Tests\Feature\Media;

use App\Models\MediaSource;
use App\Services\Media\Sources\HttpSourceGuard;
use App\Services\Media\Sources\S3SourceAdapter;
use App\Services\Media\Sources\WebDavSourceAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Mockery;
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
        Http::fake(['s3.example.test/*' => Http::response('<?xml version="1.0"?><ListBucketResult><IsTruncated>false</IsTruncated><Contents><Key>Movies/Arrival.mp4</Key><LastModified>2026-01-01T00:00:00Z</LastModified><ETag>"etag"</ETag><Size>4</Size></Contents></ListBucketResult>')]);
        $source = MediaSource::create(['name' => 'S3', 'type' => 's3', 'configuration' => ['endpoint' => 'https://s3.example.test', 'bucket' => 'media', 'prefix' => 'Movies/', 'region' => 'us-east-1', 'access_key' => 'access', 'secret_key' => 'secret']]);
        $guard = Mockery::mock(HttpSourceGuard::class);
        $guard->shouldReceive('validate')->andReturn('https://s3.example.test');
        $objects = iterator_to_array((new S3SourceAdapter($guard))->objects($source));
        $this->assertSame('Movies/Arrival.mp4', $objects[0]->locator);
        Http::assertSent(fn ($request) => str_starts_with($request->header('Authorization')[0] ?? '', 'AWS4-HMAC-SHA256 Credential=access/'));
    }

    public function test_webdav_catalog_and_range_probe_use_read_only_methods(): void
    {
        $xml = '<?xml version="1.0"?><d:multistatus xmlns:d="DAV:"><d:response><d:href>/videos/Movie.mp4</d:href><d:propstat><d:prop><d:getcontentlength>4</d:getcontentlength><d:getlastmodified>Wed, 01 Jan 2026 00:00:00 GMT</d:getlastmodified><d:getetag>"etag"</d:getetag><d:resourcetype/></d:prop></d:propstat></d:response></d:multistatus>';
        Http::fake(fn ($request) => $request->method() === 'PROPFIND'
            ? Http::response($xml, 207)
            : Http::response('x', 206, ['Content-Length' => '1', 'Content-Range' => 'bytes 0-0/4']));
        $source = MediaSource::create(['name' => 'DAV', 'type' => 'webdav', 'configuration' => ['url' => 'https://dav.example.test/videos', 'username' => 'reader', 'password' => 'secret']]);
        $guard = Mockery::mock(HttpSourceGuard::class);
        $guard->shouldReceive('validate')->andReturn('https://dav.example.test/videos');
        $adapter = new WebDavSourceAdapter($guard);
        $this->assertTrue($adapter->capabilities($source)['range']);
        Http::assertSent(fn ($request) => in_array($request->method(), ['PROPFIND', 'GET'], true));
    }
}
