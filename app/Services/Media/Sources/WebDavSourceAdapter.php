<?php

namespace App\Services\Media\Sources;

use App\Models\MediaSource;
use App\Services\Iptv\ConfidentialHttpFactory;
use GuzzleHttp\Handler\StreamHandler;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;
use Illuminate\Http\Client\Response;
use RuntimeException;
use SimpleXMLElement;
use SplQueue;
use Throwable;

class WebDavSourceAdapter implements MediaSourceAdapter
{
    public function __construct(
        private readonly HttpSourceGuard $guard,
        private readonly ConfidentialHttpFactory $http,
    ) {}

    public function objects(MediaSource $source): iterable
    {
        $config = $source->configuration;
        $target = $this->pin($source);
        $budget = SourceCatalogBudget::fromConfig();
        $collection = $this->collection($target);

        /** @var SplQueue<array{url: string, decoded_path: string}> $directories */
        $directories = new SplQueue;
        $directories->enqueue([
            'url' => $collection['url'],
            'decoded_path' => $collection['decoded_path'],
        ]);
        $visited = [$this->directoryKey($collection['decoded_path']) => true];

        while (! $directories->isEmpty()) {
            /** @var array{url: string, decoded_path: string} $directory */
            $directory = $directories->dequeue();
            $budget->consumeRequest();

            $response = $this->send(
                target: $target,
                method: 'PROPFIND',
                url: $directory['url'],
                config: $config,
                headers: ['Depth' => '1'],
                options: [
                    'body' => '<?xml version="1.0"?><d:propfind xmlns:d="DAV:"><d:prop><d:getcontentlength/><d:getlastmodified/><d:getetag/><d:resourcetype/></d:prop></d:propfind>',
                    'stream' => true,
                ],
                timeout: $budget->timeoutSeconds(30),
            );

            if (! in_array($response->status(), [207, 200], true)) {
                $budget->discard($response);

                throw new RuntimeException('webdav_catalog_failed');
            }

            $xml = $budget->parse(
                $budget->read($response),
                'webdav_catalog_invalid',
            );
            $xml->registerXPathNamespace('d', 'DAV:');

            foreach ($xml->xpath('//d:response') ?: [] as $entry) {
                $entry->registerXPathNamespace('d', 'DAV:');
                $href = (string) ($entry->xpath('d:href')[0] ?? '');
                $resolved = $this->resolveHref(
                    target: $target,
                    directoryUrl: $directory['url'],
                    href: $href,
                    baseDecodedPath: $collection['decoded_path'],
                    budget: $budget,
                );

                if ($resolved['relative'] === '') {
                    continue;
                }

                $budget->consumeItem();
                $properties = $this->successfulProperties($entry);
                if (! $properties instanceof SimpleXMLElement) {
                    continue;
                }

                $properties->registerXPathNamespace('d', 'DAV:');
                if (($properties->xpath('d:resourcetype/d:collection') ?: []) !== []) {
                    $key = $this->directoryKey($resolved['decoded_path']);
                    if (! isset($visited[$key])) {
                        $visited[$key] = true;
                        $directories->enqueue([
                            'url' => rtrim($resolved['url'], '/').'/',
                            'decoded_path' => $resolved['decoded_path'],
                        ]);
                    }

                    continue;
                }

                $date = (string) ($properties->xpath('d:getlastmodified')[0] ?? '');
                $modifiedAt = $date === '' ? false : strtotime($date);

                yield new SourceObject(
                    $resolved['relative'],
                    $resolved['relative'],
                    (int) ($properties->xpath('d:getcontentlength')[0] ?? 0),
                    trim((string) ($properties->xpath('d:getetag')[0] ?? ''), '"') ?: null,
                    $modifiedAt === false ? null : $modifiedAt,
                );
            }
        }
    }

    public function capabilities(MediaSource $source): array
    {
        $first = null;
        foreach ($this->objects($source) as $object) {
            $first = $object;

            break;
        }

        $range = false;

        if ($first !== null) {
            $probe = $this->open($source, $first->locator, 0, 0);

            try {
                $range = $probe->status === 206;
            } finally {
                $probe->body->close();
            }
        }

        return ['range' => $range, 'seekable' => $range, 'read_only' => true];
    }

    public function open(MediaSource $source, string $locator, ?int $start, ?int $end): SourceResponse
    {
        $encodedLocator = $this->encodeLocator($locator);
        $config = $source->configuration;
        $target = $this->pin($source);
        $base = rtrim($target->url, '/');
        $headers = [];
        if ($start !== null) {
            $headers['Range'] = 'bytes='.$start.'-'.($end ?? '');
        }
        $response = $this->send(
            target: $target,
            method: 'GET',
            url: $base.'/'.$encodedLocator,
            config: $config,
            headers: $headers,
            options: ['stream' => true],
            timeout: 60,
        );
        if ($response->status() === 416) {
            $response->toPsrResponse()->getBody()->close();

            throw new RuntimeException('source_range_invalid');
        }
        if (! in_array($response->status(), [200, 206], true)) {
            $response->toPsrResponse()->getBody()->close();

            throw new RuntimeException('source_read_failed');
        }

        return new SourceResponse($response->toPsrResponse()->getBody(), $response->status(), (int) ($response->header('Content-Length') ?: 0), $response->header('Content-Type', 'application/octet-stream'), $response->header('Content-Range'));
    }

    public function localPath(MediaSource $source, string $locator): ?string
    {
        return null;
    }

    private function encodeLocator(string $locator): string
    {
        $maximumBytes = min(
            16384,
            max(
                255,
                (int) config(
                    'odissey.source_catalog_max_locator_bytes',
                    4096,
                ),
            ),
        );

        if (
            $locator === ''
            || strlen($locator) > $maximumBytes
            || str_starts_with($locator, '/')
            || str_starts_with($locator, '\\')
        ) {
            throw new RuntimeException('source_read_failed');
        }

        $candidate = $locator;

        for ($depth = 0; $depth < 16; $depth++) {
            if (
                preg_match('/[\x00-\x1F\x7F\\\\]/', $candidate) === 1
                || preg_match('/%(?:2f|5c)/i', $candidate) === 1
                || collect(explode('/', $candidate))->contains(
                    fn (string $segment): bool => $segment === ''
                        || in_array($segment, ['.', '..'], true),
                )
            ) {
                throw new RuntimeException('source_read_failed');
            }

            $decoded = rawurldecode($candidate);

            if ($decoded === $candidate) {
                return implode(
                    '/',
                    array_map(rawurlencode(...), explode('/', $locator)),
                );
            }

            $candidate = $decoded;
        }

        throw new RuntimeException('source_read_failed');
    }

    private function pin(MediaSource $source): PinnedSourceTarget
    {
        try {
            return $this->guard->pin(
                $source->configuration['url'],
                (bool) $source->allow_private_network,
            );
        } catch (Throwable) {
            throw new RuntimeException('webdav_request_failed');
        }
    }

    /**
     * @return array{url: string, decoded_path: string}
     */
    private function collection(PinnedSourceTarget $target): array
    {
        try {
            $uri = new Uri($target->url);
            if (
                $uri->getQuery() !== ''
                || $uri->getFragment() !== ''
                || $uri->getUserInfo() !== ''
            ) {
                throw new RuntimeException('webdav_request_failed');
            }

            $encodedPath = $uri->getPath() === '' ? '/' : $uri->getPath();
            $this->assertSafeEncodedPath($encodedPath, 'webdav_request_failed');
            $decodedPath = rawurldecode($encodedPath);
            $decodedPath = $decodedPath === '/' ? '/' : rtrim($decodedPath, '/');
            $encodedPath = $encodedPath === '/' ? '/' : rtrim($encodedPath, '/').'/';

            return [
                'url' => strtolower($uri->getScheme()).'://'.$target->authority().$encodedPath,
                'decoded_path' => $decodedPath,
            ];
        } catch (RuntimeException $exception) {
            if ($exception->getMessage() === 'webdav_request_failed') {
                throw $exception;
            }

            throw new RuntimeException('webdav_request_failed');
        } catch (Throwable) {
            throw new RuntimeException('webdav_request_failed');
        }
    }

    /**
     * @return array{url: string, decoded_path: string, relative: string}
     */
    private function resolveHref(
        PinnedSourceTarget $target,
        string $directoryUrl,
        string $href,
        string $baseDecodedPath,
        SourceCatalogBudget $budget,
    ): array {
        if (
            $href === ''
            || strlen($href) > 16384
            || preg_match('/[\x00-\x1F\x7F\\\\]/', $href) === 1
        ) {
            throw new RuntimeException('webdav_href_invalid');
        }

        try {
            $reference = new Uri($href);
            $this->assertSafeEncodedPath($reference->getPath());
            $resolved = UriResolver::resolve(new Uri($directoryUrl), $reference);
        } catch (RuntimeException $exception) {
            if ($exception->getMessage() === 'webdav_href_invalid') {
                throw $exception;
            }

            throw new RuntimeException('webdav_href_invalid');
        } catch (Throwable) {
            throw new RuntimeException('webdav_href_invalid');
        }

        $scheme = strtolower($resolved->getScheme());
        $host = strtolower(trim($resolved->getHost(), '[]'));
        $port = $resolved->getPort() ?? ($scheme === 'https' ? 443 : 80);

        if (
            $scheme !== strtolower((string) parse_url($target->url, PHP_URL_SCHEME))
            || $host !== $target->host
            || $port !== $target->port
            || $resolved->getUserInfo() !== ''
            || $resolved->getQuery() !== ''
            || $resolved->getFragment() !== ''
        ) {
            throw new RuntimeException('webdav_href_invalid');
        }

        $encodedPath = $resolved->getPath();
        $this->assertSafeEncodedPath($encodedPath);
        $decodedPath = rawurldecode($encodedPath);
        $normalizedBase = $baseDecodedPath === '/' ? '/' : rtrim($baseDecodedPath, '/');
        $normalizedPath = $decodedPath === '/' ? '/' : rtrim($decodedPath, '/');

        if (
            $normalizedBase !== '/'
            && $normalizedPath !== $normalizedBase
            && ! str_starts_with($normalizedPath, $normalizedBase.'/')
        ) {
            throw new RuntimeException('webdav_href_invalid');
        }

        $relative = $normalizedBase === '/'
            ? ltrim($normalizedPath, '/')
            : ltrim(substr($normalizedPath, strlen($normalizedBase)), '/');

        if ($relative !== '') {
            $budget->assertLocator($relative);
        }

        return [
            'url' => $scheme.'://'.$target->authority().$encodedPath,
            'decoded_path' => $normalizedPath,
            'relative' => $relative,
        ];
    }

    private function assertSafeEncodedPath(
        string $path,
        string $errorCode = 'webdav_href_invalid',
    ): void {
        if (
            $path === ''
            || strlen($path) > 16384
            || preg_match('/[\x00-\x1F\x7F\\\\]/', $path) === 1
            || preg_match('/%(?![0-9A-Fa-f]{2})/', $path) === 1
        ) {
            throw new RuntimeException($errorCode);
        }

        $candidate = $path;

        for ($depth = 0; $depth < 16; $depth++) {
            if (preg_match('/%(?:2f|5c)/i', $candidate) === 1) {
                throw new RuntimeException($errorCode);
            }

            $decoded = rawurldecode($candidate);
            if (
                preg_match('/[\x00-\x1F\x7F\\\\]/', $decoded) === 1
                || collect(explode('/', $decoded))->contains(
                    fn (string $segment): bool => in_array($segment, ['.', '..'], true),
                )
            ) {
                throw new RuntimeException($errorCode);
            }

            if ($decoded === $candidate) {
                return;
            }

            $candidate = $decoded;
        }

        throw new RuntimeException($errorCode);
    }

    private function successfulProperties(SimpleXMLElement $entry): ?SimpleXMLElement
    {
        foreach ($entry->xpath('d:propstat') ?: [] as $propstat) {
            $propstat->registerXPathNamespace('d', 'DAV:');
            $status = trim((string) ($propstat->xpath('d:status')[0] ?? ''));

            if (
                $status !== ''
                && preg_match('/^HTTP\\/\\S+\\s+2\\d\\d(?:\\s|$)/i', $status) !== 1
            ) {
                continue;
            }

            $properties = $propstat->xpath('d:prop')[0] ?? null;

            return $properties instanceof SimpleXMLElement ? $properties : null;
        }

        return null;
    }

    private function directoryKey(string $decodedPath): string
    {
        return $decodedPath === '/' ? '/' : rtrim($decodedPath, '/').'/';
    }

    private function send(
        PinnedSourceTarget $target,
        string $method,
        string $url,
        array $config,
        array $headers,
        array $options,
        int $timeout,
    ): Response {
        try {
            $request = $this->http->createPendingRequest();
            $stream = (bool) ($options['stream'] ?? false);
            $connectTimeout = min(10, max(1, $timeout));
            $requestTimeout = $timeout;

            if ($stream) {
                $request->setHandler(new StreamHandler);
                $requestTimeout = $connectTimeout;
                $options = array_merge(
                    $options,
                    $target->streamOptions(),
                    ['read_timeout' => min(60, max(1, $timeout))],
                );
                $url = $target->connectUrl($url);
            } else {
                $options['curl'] = $target->curlOptions();
            }

            return $request
                ->timeout($requestTimeout)
                ->connectTimeout($connectTimeout)
                ->withoutRedirecting()
                ->withBasicAuth(
                    $config['username'] ?? '',
                    $config['password'] ?? '',
                )
                ->withOptions($options)
                ->withHeaders([
                    'Host' => $target->authority(),
                    ...$headers,
                ])
                ->send($method, $url);
        } catch (Throwable) {
            throw new RuntimeException('webdav_request_failed');
        }
    }
}
