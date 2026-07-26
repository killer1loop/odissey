<?php

namespace App\Services\Media\Sources;

use App\Models\MediaSource;
use App\Services\Iptv\ConfidentialHttpFactory;
use GuzzleHttp\Handler\StreamHandler;
use RuntimeException;
use Throwable;

class S3SourceAdapter implements MediaSourceAdapter
{
    public function __construct(
        private readonly HttpSourceGuard $guard,
        private readonly ConfidentialHttpFactory $http,
    ) {}

    public function objects(MediaSource $source): iterable
    {
        $budget = SourceCatalogBudget::fromConfig(
            min(
                10000,
                max(1, (int) config('odissey.source_catalog_max_s3_pages', 250)),
            ),
        );
        $seenTokens = [];
        $token = null;
        $target = $this->pin($source);

        do {
            $budget->consumeRequest();
            $query = ['list-type' => '2', 'prefix' => $source->configuration['prefix'] ?? '', 'max-keys' => '1000'];

            if ($token !== null) {
                $query['continuation-token'] = $token;
            }

            $xml = $budget->parse(
                $this->request($source, $target, 'GET', '', $query, $budget),
                's3_catalog_invalid',
            );

            foreach ($xml->Contents ?? [] as $object) {
                $budget->consumeItem();
                $key = (string) $object->Key;
                $budget->assertLocator($key);

                if (! str_ends_with($key, '/')) {
                    yield new SourceObject($key, $key, (int) $object->Size, trim((string) $object->ETag, '"'), strtotime((string) $object->LastModified));
                }
            }

            if (strtolower(trim((string) ($xml->IsTruncated ?? 'false'))) !== 'true') {
                $token = null;

                continue;
            }

            $nextToken = (string) ($xml->NextContinuationToken ?? '');
            if (
                $nextToken === ''
                || strlen($nextToken) > 16384
                || preg_match('/[\x00-\x1F\x7F]/', $nextToken) === 1
            ) {
                throw new RuntimeException('s3_pagination_invalid');
            }

            if (isset($seenTokens[$nextToken])) {
                throw new RuntimeException('s3_pagination_cycle');
            }

            $seenTokens[$nextToken] = true;
            $token = $nextToken;
        } while ($token !== null);
    }

    public function capabilities(MediaSource $source): array
    {
        $budget = SourceCatalogBudget::fromConfig(1);
        $budget->consumeRequest();
        $target = $this->pin($source);
        $budget->parse(
            $this->request(
                $source,
                $target,
                'GET',
                '',
                ['list-type' => '2', 'max-keys' => '1'],
                $budget,
            ),
            's3_catalog_invalid',
        );

        return ['range' => true, 'seekable' => true, 'read_only' => true];
    }

    public function open(MediaSource $source, string $locator, ?int $start, ?int $end): SourceResponse
    {
        $headers = $start === null ? [] : ['Range' => 'bytes='.$start.'-'.($end ?? '')];
        $response = $this->signedResponse(
            $source,
            $this->pin($source),
            'GET',
            $locator,
            [],
            $headers,
            true,
        );
        if ($response->status() === 416) {
            $response->toPsrResponse()->getBody()->close();

            throw new RuntimeException('source_range_invalid');
        }
        if (! in_array($response->status(), [200, 206], true)) {
            $response->toPsrResponse()->getBody()->close();

            throw new RuntimeException('s3_read_failed');
        }

        return new SourceResponse($response->toPsrResponse()->getBody(), $response->status(), (int) ($response->header('Content-Length') ?: 0), $response->header('Content-Type', 'application/octet-stream'), $response->header('Content-Range'));
    }

    public function localPath(MediaSource $source, string $locator): ?string
    {
        return null;
    }

    private function request(
        MediaSource $source,
        PinnedSourceTarget $target,
        string $method,
        string $key,
        array $query,
        SourceCatalogBudget $budget,
    ): string {
        $response = $this->signedResponse(
            $source,
            $target,
            $method,
            $key,
            $query,
            stream: true,
            timeoutSeconds: $budget->timeoutSeconds(60),
        );

        if (! $response->successful()) {
            $budget->discard($response);

            throw new RuntimeException('s3_request_failed');
        }

        return $budget->read($response);
    }

    private function signedResponse(
        MediaSource $source,
        PinnedSourceTarget $target,
        string $method,
        string $key,
        array $query = [],
        array $extraHeaders = [],
        bool $stream = false,
        int $timeoutSeconds = 60,
    ) {
        try {
            $c = $source->configuration;
            $endpoint = rtrim($target->url, '/');
            $region = $c['region'] ?? 'us-east-1';
            $bucket = $c['bucket'];
            $path = '/'.rawurlencode($bucket).($key !== '' ? '/'.str_replace('%2F', '/', rawurlencode($key)) : '');
            ksort($query);
            $canonicalQuery = http_build_query($query, '', '&', PHP_QUERY_RFC3986);
            $now = now('UTC');
            $date = $now->format('Ymd');
            $amzDate = $now->format('Ymd\THis\Z');
            $payloadHash = hash('sha256', '');
            $headers = array_change_key_case(array_merge(['host' => $target->authority(), 'x-amz-content-sha256' => $payloadHash, 'x-amz-date' => $amzDate], $extraHeaders), CASE_LOWER);
            ksort($headers);
            $canonicalHeaders = collect($headers)->map(fn ($v, $k) => strtolower($k).':'.trim($v)."\n")->implode('');
            $signedHeaders = implode(';', array_keys($headers));
            $canonical = "{$method}\n{$path}\n{$canonicalQuery}\n{$canonicalHeaders}\n{$signedHeaders}\n{$payloadHash}";
            $scope = "{$date}/{$region}/s3/aws4_request";
            $toSign = "AWS4-HMAC-SHA256\n{$amzDate}\n{$scope}\n".hash('sha256', $canonical);
            $hmac = fn ($key, $data) => hash_hmac('sha256', $data, $key, true);
            $signingKey = $hmac($hmac($hmac($hmac('AWS4'.$c['secret_key'], $date), $region), 's3'), 'aws4_request');
            $headers['Authorization'] = 'AWS4-HMAC-SHA256 Credential='.$c['access_key'].'/'.$scope.', SignedHeaders='.$signedHeaders.', Signature='.hash_hmac('sha256', $toSign, $signingKey);

            $url = $endpoint.$path.($canonicalQuery ? '?'.$canonicalQuery : '');
            $options = [
                'stream' => false,
                'curl' => $target->curlOptions(),
            ];
            $request = $this->http->createPendingRequest();
            $connectTimeoutSeconds = min(10, max(1, $timeoutSeconds));
            $requestTimeoutSeconds = $timeoutSeconds;

            if ($stream) {
                $request->setHandler(new StreamHandler);
                $requestTimeoutSeconds = $connectTimeoutSeconds;
                $options = array_merge(
                    [
                        'stream' => true,
                        'read_timeout' => min(60, max(1, $timeoutSeconds)),
                    ],
                    $target->streamOptions(),
                );
                $url = $target->connectUrl($url);
            }

            return $request
                ->timeout($requestTimeoutSeconds)
                ->connectTimeout($connectTimeoutSeconds)
                ->withoutRedirecting()
                ->withOptions($options)
                ->withHeaders($headers)
                ->send($method, $url);
        } catch (Throwable) {
            throw new RuntimeException('s3_request_failed');
        }
    }

    private function pin(MediaSource $source): PinnedSourceTarget
    {
        try {
            return $this->guard->pin(
                $source->configuration['endpoint'] ?? 'https://s3.amazonaws.com',
                (bool) $source->allow_private_network,
            );
        } catch (Throwable) {
            throw new RuntimeException('s3_request_failed');
        }
    }
}
