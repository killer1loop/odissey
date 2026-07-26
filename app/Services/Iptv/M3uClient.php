<?php

namespace App\Services\Iptv;

use App\Models\Iptv\IptvProvider;
use App\Services\Iptv\Exceptions\SanitizedIptvException;

class M3uClient
{
    /**
     * Playlist parsing builds PHP arrays and therefore expands the wire
     * representation substantially. Keep the hard ceiling compatible with
     * PHP's 128 MiB production memory limit even if an operator configures a
     * larger value.
     */
    private const MAX_PLAYLIST_BYTES = 8 * 1024 * 1024;

    private const MAX_PLAYLIST_CHANNELS = 20000;

    public function __construct(
        private readonly BoundedIptvDocumentFetcher $documents,
    ) {}

    /** @return array{0: array<int, array<string,mixed>>, 1: array<int, array<string,mixed>>} */
    public function catalog(IptvProvider $provider): array
    {
        $url = $provider->config['playlist_url'] ?? '';
        $maxBytes = min(
            self::MAX_PLAYLIST_BYTES,
            max(1, (int) config('iptv.playlist_max_bytes')),
        );
        $body = $this->documents->fetch(
            url: $url,
            allowInsecureHttp: (bool) $provider->allow_insecure_http,
            maxBytes: $maxBytes,
            timeoutSeconds: 30,
            unavailableCode: 'playlist_unavailable',
            invalidCode: 'playlist_invalid',
        );

        if (! str_starts_with(ltrim($body), '#EXTM3U')) {
            throw new SanitizedIptvException('playlist_invalid', 422);
        }
        $groups = [];
        $streams = [];
        $pending = null;
        $maxChannels = min(
            self::MAX_PLAYLIST_CHANNELS,
            max(1, (int) config('iptv.playlist_max_channels')),
        );

        foreach (preg_split('/\R/', $body) ?: [] as $line) {
            $line = trim($line);
            if (str_starts_with($line, '#EXTINF:')) {
                preg_match_all('/([\w-]+)="([^"]*)"/u', $line, $matches, PREG_SET_ORDER);
                $attributes = [];
                foreach ($matches as $match) {
                    $attributes[$match[1]] = $match[2];
                }
                $pending = ['attributes' => $attributes, 'name' => trim(substr($line, strrpos($line, ',') + 1))];
            } elseif ($pending !== null && preg_match('#^https?://#i', $line)) {
                $group = trim($pending['attributes']['group-title'] ?? 'Other') ?: 'Other';
                $groupId = hash('sha256', $group);
                $groups[$groupId] = ['category_id' => $groupId, 'category_name' => $group];
                $external = trim($pending['attributes']['tvg-id'] ?? '') ?: hash('sha256', $line);
                $streams[] = [
                    'stream_id' => $external, 'name' => $pending['name'] ?: 'Unnamed channel',
                    'category_id' => $groupId, 'epg_channel_id' => $pending['attributes']['tvg-id'] ?? null,
                    'stream_icon' => $pending['attributes']['tvg-logo'] ?? null, 'stream_url' => $line,
                    'num' => count($streams) + 1,
                ];
                if (count($streams) >= $maxChannels) {
                    break;
                }
                $pending = null;
            }
        }

        return [array_values($groups), $streams];
    }
}
