<?php

namespace App\Services\Media\Sources;

use App\Models\MediaSource;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use SimpleXMLElement;

class WebDavSourceAdapter implements MediaSourceAdapter
{
    public function __construct(private readonly HttpSourceGuard $guard) {}

    public function objects(MediaSource $source): iterable
    {
        $config = $source->configuration;
        $base = $this->guard->validate($config['url'], (bool) $source->allow_private_network);
        $response = Http::timeout(30)->withBasicAuth($config['username'] ?? '', $config['password'] ?? '')
            ->withHeaders(['Depth' => 'infinity'])
            ->send('PROPFIND', $base.'/', ['body' => '<?xml version="1.0"?><d:propfind xmlns:d="DAV:"><d:prop><d:getcontentlength/><d:getlastmodified/><d:getetag/><d:resourcetype/></d:prop></d:propfind>']);
        if (! in_array($response->status(), [207, 200], true) || strlen($response->body()) > config('odissey.source_catalog_max_bytes')) {
            throw new RuntimeException('webdav_catalog_failed');
        }
        $xml = new SimpleXMLElement($response->body());
        $xml->registerXPathNamespace('d', 'DAV:');
        foreach ($xml->xpath('//d:response') ?: [] as $entry) {
            $href = rawurldecode((string) ($entry->xpath('d:href')[0] ?? ''));
            $properties = $entry->xpath('d:propstat/d:prop')[0] ?? null;
            if ($properties === null || $properties->xpath('d:resourcetype/d:collection')) {
                continue;
            }
            $path = ltrim(parse_url($href, PHP_URL_PATH) ?? '', '/');
            $basePath = ltrim(parse_url($base, PHP_URL_PATH) ?? '', '/');
            $relative = ltrim(str_starts_with($path, $basePath) ? substr($path, strlen($basePath)) : $path, '/');
            yield new SourceObject(
                $relative,
                $relative,
                (int) ($properties->xpath('d:getcontentlength')[0] ?? 0),
                trim((string) ($properties->xpath('d:getetag')[0] ?? ''), '"') ?: null,
                ($date = (string) ($properties->xpath('d:getlastmodified')[0] ?? '')) ? strtotime($date) : null,
            );
        }
    }

    public function capabilities(MediaSource $source): array
    {
        $first = collect($this->objects($source))->first();
        $range = $first ? $this->open($source, $first->locator, 0, 0)->status === 206 : false;

        return ['range' => $range, 'seekable' => $range, 'read_only' => true];
    }

    public function open(MediaSource $source, string $locator, ?int $start, ?int $end): SourceResponse
    {
        $config = $source->configuration;
        $base = $this->guard->validate($config['url'], (bool) $source->allow_private_network);
        $headers = [];
        if ($start !== null) {
            $headers['Range'] = 'bytes='.$start.'-'.($end ?? '');
        }
        $response = Http::timeout(60)->withBasicAuth($config['username'] ?? '', $config['password'] ?? '')
            ->withOptions(['stream' => true])
            ->withHeaders($headers)->get($base.'/'.str_replace('%2F', '/', rawurlencode($locator)));
        if (! in_array($response->status(), [200, 206], true)) {
            throw new RuntimeException('source_read_failed');
        }

        return new SourceResponse($response->toPsrResponse()->getBody(), $response->status(), (int) ($response->header('Content-Length') ?: 0), $response->header('Content-Type', 'application/octet-stream'), $response->header('Content-Range'));
    }

    public function localPath(MediaSource $source, string $locator): ?string
    {
        return null;
    }
}
