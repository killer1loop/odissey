<?php

namespace App\Services\Iptv;

use App\Models\Iptv\IptvPlaybackResource;
use App\Services\Iptv\Exceptions\SanitizedIptvException;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;
use Throwable;

class HlsPlaylistRewriter
{
    /**
     * Tags needed for playback are retained. Optional metadata, variables,
     * comments, and unknown extensions are removed or rejected below so the
     * upstream cannot smuggle credentials into the browser-facing manifest.
     *
     * @var array<int, string>
     */
    private const ALLOWED_TAGS = [
        '#EXTM3U',
        '#EXTINF',
        '#EXT-X-BITRATE',
        '#EXT-X-BYTERANGE',
        '#EXT-X-DISCONTINUITY',
        '#EXT-X-DISCONTINUITY-SEQUENCE',
        '#EXT-X-ENDLIST',
        '#EXT-X-GAP',
        '#EXT-X-I-FRAME-STREAM-INF',
        '#EXT-X-I-FRAMES-ONLY',
        '#EXT-X-IMAGE-STREAM-INF',
        '#EXT-X-IMAGES-ONLY',
        '#EXT-X-INDEPENDENT-SEGMENTS',
        '#EXT-X-KEY',
        '#EXT-X-MAP',
        '#EXT-X-MEDIA',
        '#EXT-X-MEDIA-SEQUENCE',
        '#EXT-X-PART',
        '#EXT-X-PART-INF',
        '#EXT-X-PLAYLIST-TYPE',
        '#EXT-X-PRELOAD-HINT',
        '#EXT-X-PROGRAM-DATE-TIME',
        '#EXT-X-RENDITION-REPORT',
        '#EXT-X-SERVER-CONTROL',
        '#EXT-X-SESSION-KEY',
        '#EXT-X-SKIP',
        '#EXT-X-START',
        '#EXT-X-STREAM-INF',
        '#EXT-X-TARGETDURATION',
        '#EXT-X-TILES',
        '#EXT-X-VERSION',
    ];

    private const STRIPPED_METADATA_TAGS = [
        '#EXT-X-DATERANGE',
        '#EXT-X-SESSION-DATA',
    ];

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

        $lines = $this->sanitizeMetadata($lines);

        if (
            $this->resourceReferenceCount($lines)
            > min(1024, max(1, (int) config('iptv.manifest_max_resources')))
        ) {
            throw new SanitizedIptvException('manifest_resource_limit');
        }

        $expectPlaylist = false;
        $validatedOrigins = [];
        $maximumOrigins = min(
            32,
            max(1, (int) config('iptv.manifest_max_origins', 16)),
        );

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
                        &$validatedOrigins,
                        $maximumOrigins,
                    ): string {
                        $url = $this->resolve($parent->upstream_url, $matches[1]);
                        $this->assertOriginAllowed(
                            $url,
                            $allowInsecureHttp,
                            $validatedOrigins,
                            $maximumOrigins,
                        );
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
            $this->assertOriginAllowed(
                $url,
                $allowInsecureHttp,
                $validatedOrigins,
                $maximumOrigins,
            );
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

        $rewritten = implode("\n", $lines);
        $this->assertSecretsAreNotExposed($rewritten, $parent);

        return $rewritten;
    }

    /**
     * @param  array<string, true>  $validatedOrigins
     */
    private function assertOriginAllowed(
        string $url,
        bool $allowInsecureHttp,
        array &$validatedOrigins,
        int $maximumOrigins,
    ): void {
        $origin = $this->urlGuard->normalizedOrigin(
            $url,
            $allowInsecureHttp,
        );

        if (isset($validatedOrigins[$origin])) {
            return;
        }

        if (count($validatedOrigins) >= $maximumOrigins) {
            throw new SanitizedIptvException('manifest_origin_limit');
        }

        $this->urlGuard->assertPublicTarget($url, $allowInsecureHttp);
        $validatedOrigins[$origin] = true;
    }

    /**
     * @param  array<int, string>  $lines
     * @return array<int, string>
     */
    private function sanitizeMetadata(array $lines): array
    {
        $sanitized = [];

        foreach ($lines as $line) {
            if (preg_match('/\{\$[A-Za-z0-9_-]+\}/', $line) === 1) {
                throw new SanitizedIptvException('invalid_hls_manifest');
            }

            $trimmed = trim($line);

            if (! str_starts_with($trimmed, '#')) {
                $sanitized[] = $line;

                continue;
            }

            $tag = strtoupper(strtok($trimmed, ':') ?: $trimmed);

            if ($tag === '#EXT-X-DEFINE') {
                throw new SanitizedIptvException('invalid_hls_manifest');
            }

            if (
                in_array($tag, self::STRIPPED_METADATA_TAGS, true)
                || ! in_array($tag, self::ALLOWED_TAGS, true)
            ) {
                continue;
            }

            $sanitized[] = $line;
        }

        return $sanitized;
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
        $grant = request()->attributes->get('nativePlaybackGrantId');
        $grantToken = request()->attributes->get('nativePlaybackGrantToken');
        if (
            $grant !== null
            && is_string($grantToken)
            && $grantToken !== ''
            && request()->routeIs('api.v1.playback.live.*')
        ) {
            return route('api.v1.playback.live.resource', [
                'grant' => is_object($grant) && method_exists($grant, 'getKey')
                    ? $grant->getKey()
                    : $grant,
                'grantToken' => $grantToken,
                'session' => $parent->iptv_playback_session_id,
                'resource' => $resource->id,
            ], absolute: false);
        }

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
            '#EXT-X-MEDIA',
            '#EXT-X-I-FRAME-STREAM-INF',
            '#EXT-X-IMAGE-STREAM-INF',
            '#EXT-X-RENDITION-REPORT' => 'playlist',
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

    private function assertSecretsAreNotExposed(
        string $playlist,
        IptvPlaybackResource $parent,
    ): void {
        $provider = $parent->session->channel->provider;
        $secrets = [
            (string) $provider->username,
            (string) $provider->password,
        ];
        $query = (string) parse_url($parent->upstream_url, PHP_URL_QUERY);

        if ($query !== '') {
            parse_str($query, $parameters);
            array_walk_recursive(
                $parameters,
                static function (mixed $value) use (&$secrets): void {
                    if (is_scalar($value)) {
                        $secrets[] = (string) $value;
                    }
                },
            );
        }

        foreach (array_unique($secrets) as $secret) {
            if (strlen($secret) < 4) {
                continue;
            }

            if (
                str_contains($playlist, $secret)
                || str_contains($playlist, rawurlencode($secret))
            ) {
                throw new SanitizedIptvException('invalid_hls_manifest');
            }
        }
    }
}
