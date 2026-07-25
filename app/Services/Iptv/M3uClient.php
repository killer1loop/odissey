<?php

namespace App\Services\Iptv;

use App\Models\Iptv\IptvProvider;
use App\Services\Iptv\Exceptions\SanitizedIptvException;
use Illuminate\Support\Facades\Http;

class M3uClient
{
    /** @return array{0: array<int, array<string,mixed>>, 1: array<int, array<string,mixed>>} */
    public function catalog(IptvProvider $provider): array
    {
        $url = $provider->config['playlist_url'] ?? '';
        app(UpstreamUrlGuard::class)->assertPublicTarget($url, (bool) $provider->allow_insecure_http);
        $response = Http::timeout(30)->maxRedirects(0)->get($url);
        if (! $response->successful()) {
            throw new SanitizedIptvException('playlist_unavailable', 502);
        }
        $body = $response->body();
        if (strlen($body) > config('iptv.playlist_max_bytes') || ! str_starts_with(ltrim($body), '#EXTM3U')) {
            throw new SanitizedIptvException('playlist_invalid', 422);
        }
        $groups = [];
        $streams = [];
        $pending = null;
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
                if (count($streams) >= config('iptv.playlist_max_channels')) {
                    break;
                }
                $pending = null;
            }
        }

        return [array_values($groups), $streams];
    }
}
