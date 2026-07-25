<?php

namespace App\Services\Iptv;

use App\Models\Iptv\IptvPlaybackResource;
use App\Services\Iptv\Exceptions\SanitizedIptvException;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;
use Throwable;

class HlsPlaylistRewriter
{
    public function __construct(
        private readonly PlaybackResourceRepository $resources,
        private readonly UpstreamUrlGuard $urlGuard,
    ) {}

    public function rewrite(string $playlist, IptvPlaybackResource $parent): string
    {
        $session = $parent->session;
        $session->loadMissing('channel.provider');
        $allowInsecureHttp = $session->channel->provider->allow_insecure_http;
        $lines = preg_split('/\r\n|\r|\n/', $playlist);

        if (! is_array($lines) || trim((string) ($lines[0] ?? '')) !== '#EXTM3U') {
            throw new SanitizedIptvException('invalid_hls_manifest');
        }

        if (
            $this->resourceReferenceCount($lines)
            > min(1024, max(1, (int) config('iptv.manifest_max_resources')))
        ) {
            throw new SanitizedIptvException('manifest_resource_limit');
        }

        $expectPlaylist = false;

        foreach ($lines as $index => $line) {
            $trimmed = trim($line);

            if ($trimmed === '') {
                continue;
            }

            if (str_starts_with($trimmed, '#')) {
                $resourceType = $this->attributeResourceType($trimmed);
                $lines[$index] = preg_replace_callback(
                    '/URI="([^"]+)"/i',
                    function (array $matches) use (
                        $parent,
                        $resourceType,
                        $allowInsecureHttp,
                    ): string {
                        $url = $this->resolve($parent->upstream_url, $matches[1]);
                        $this->urlGuard->assertPublicTarget($url, $allowInsecureHttp);
                        $resource = $this->resources->create(
                            $parent->session,
                            $url,
                            $resourceType,
                            $parent,
                        );

                        return 'URI="'.$this->routeFor($parent, $resource).'"';
                    },
                    $line,
                ) ?? $line;

                $expectPlaylist = str_starts_with(
                    strtoupper($trimmed),
                    '#EXT-X-STREAM-INF:',
                );

                continue;
            }

            $url = $this->resolve($parent->upstream_url, $trimmed);
            $this->urlGuard->assertPublicTarget($url, $allowInsecureHttp);
            $resourceType = $expectPlaylist || $this->looksLikePlaylist($url)
                ? 'playlist'
                : 'segment';
            $resource = $this->resources->create(
                $parent->session,
                $url,
                $resourceType,
                $parent,
            );
            $lines[$index] = $this->routeFor($parent, $resource);
            $expectPlaylist = false;
        }

        return implode("\n", $lines);
    }

    private function resolve(string $baseUrl, string $reference): string
    {
        try {
            return (string) UriResolver::resolve(new Uri($baseUrl), new Uri($reference));
        } catch (Throwable) {
            throw new SanitizedIptvException('invalid_hls_resource');
        }
    }

    private function routeFor(
        IptvPlaybackResource $parent,
        IptvPlaybackResource $resource,
    ): string {
        return route('iptv.playback.resource', [
            'session' => $parent->iptv_playback_session_id,
            'resource' => $resource->id,
        ], absolute: false);
    }

    private function looksLikePlaylist(string $url): bool
    {
        $path = strtolower((string) parse_url($url, PHP_URL_PATH));

        return str_ends_with($path, '.m3u8') || str_ends_with($path, '.m3u');
    }

    private function attributeResourceType(string $line): string
    {
        $tag = strtoupper(strtok($line, ':') ?: $line);

        return match ($tag) {
            '#EXT-X-MEDIA', '#EXT-X-I-FRAME-STREAM-INF' => 'playlist',
            '#EXT-X-KEY', '#EXT-X-SESSION-KEY' => 'key',
            '#EXT-X-MAP' => 'init',
            default => 'resource',
        };
    }

    /**
     * @param  array<int, string>  $lines
     */
    private function resourceReferenceCount(array $lines): int
    {
        $count = 0;

        foreach ($lines as $line) {
            $trimmed = trim($line);

            if ($trimmed === '') {
                continue;
            }

            if (! str_starts_with($trimmed, '#')) {
                $count++;

                continue;
            }

            $count += preg_match_all('/URI="[^"]+"/i', $line);
        }

        return $count;
    }
}
