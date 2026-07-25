<?php

namespace App\Services\Media\Sources;

use App\Models\MediaSource;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use SimpleXMLElement;

class S3SourceAdapter implements MediaSourceAdapter
{
    public function __construct(private readonly HttpSourceGuard $guard) {}

    public function objects(MediaSource $source): iterable
    {
        $token = null;
        do {
            $query = ['list-type' => '2', 'prefix' => $source->configuration['prefix'] ?? '', 'max-keys' => '1000'];
            if ($token) {
                $query['continuation-token'] = $token;
            }
            $response = $this->request($source, 'GET', '', $query);
            $xml = new SimpleXMLElement($response);
            foreach ($xml->Contents ?? [] as $object) {
                $key = (string) $object->Key;
                if (! str_ends_with($key, '/')) {
                    yield new SourceObject($key, $key, (int) $object->Size, trim((string) $object->ETag, '"'), strtotime((string) $object->LastModified));
                }
            }
            $token = ((string) ($xml->IsTruncated ?? 'false')) === 'true' ? (string) ($xml->NextContinuationToken ?? '') : null;
        } while ($token);
    }

    public function capabilities(MediaSource $source): array
    {
        $this->request($source, 'GET', '', ['list-type' => '2', 'max-keys' => '1']);

        return ['range' => true, 'seekable' => true, 'read_only' => true];
    }

    public function open(MediaSource $source, string $locator, ?int $start, ?int $end): SourceResponse
    {
        $headers = $start === null ? [] : ['Range' => 'bytes='.$start.'-'.($end ?? '')];
        $response = $this->signedResponse($source, 'GET', $locator, [], $headers, true);
        if (! in_array($response->status(), [200, 206], true)) {
            throw new RuntimeException('s3_read_failed');
        }

        return new SourceResponse($response->toPsrResponse()->getBody(), $response->status(), (int) ($response->header('Content-Length') ?: 0), $response->header('Content-Type', 'application/octet-stream'), $response->header('Content-Range'));
    }

    public function localPath(MediaSource $source, string $locator): ?string
    {
        return null;
    }

    private function request(MediaSource $source, string $method, string $key, array $query): string
    {
        $response = $this->signedResponse($source, $method, $key, $query);
        if (! $response->successful() || strlen($response->body()) > config('odissey.source_catalog_max_bytes')) {
            throw new RuntimeException('s3_request_failed');
        }

        return $response->body();
    }

    private function signedResponse(MediaSource $source, string $method, string $key, array $query = [], array $extraHeaders = [], bool $stream = false)
    {
        $c = $source->configuration;
        $endpoint = $this->guard->validate($c['endpoint'] ?? 'https://s3.amazonaws.com', (bool) $source->allow_private_network);
        $region = $c['region'] ?? 'us-east-1';
        $bucket = $c['bucket'];
        $path = '/'.rawurlencode($bucket).($key !== '' ? '/'.str_replace('%2F', '/', rawurlencode($key)) : '');
        ksort($query);
        $canonicalQuery = http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        $now = now('UTC');
        $date = $now->format('Ymd');
        $amzDate = $now->format('Ymd\THis\Z');
        $host = parse_url($endpoint, PHP_URL_HOST);
        $payloadHash = hash('sha256', '');
        $headers = array_change_key_case(array_merge(['host' => $host, 'x-amz-content-sha256' => $payloadHash, 'x-amz-date' => $amzDate], $extraHeaders), CASE_LOWER);
        ksort($headers);
        $canonicalHeaders = collect($headers)->map(fn ($v, $k) => strtolower($k).':'.trim($v)."\n")->implode('');
        $signedHeaders = implode(';', array_keys($headers));
        $canonical = "{$method}\n{$path}\n{$canonicalQuery}\n{$canonicalHeaders}\n{$signedHeaders}\n{$payloadHash}";
        $scope = "{$date}/{$region}/s3/aws4_request";
        $toSign = "AWS4-HMAC-SHA256\n{$amzDate}\n{$scope}\n".hash('sha256', $canonical);
        $hmac = fn ($key, $data) => hash_hmac('sha256', $data, $key, true);
        $signingKey = $hmac($hmac($hmac($hmac('AWS4'.$c['secret_key'], $date), $region), 's3'), 'aws4_request');
        $headers['Authorization'] = 'AWS4-HMAC-SHA256 Credential='.$c['access_key'].'/'.$scope.', SignedHeaders='.$signedHeaders.', Signature='.hash_hmac('sha256', $toSign, $signingKey);

        return Http::timeout(60)->withOptions(['stream' => $stream])->withHeaders($headers)->send($method, $endpoint.$path.($canonicalQuery ? '?'.$canonicalQuery : ''));
    }
}
