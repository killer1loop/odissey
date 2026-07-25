<?php

namespace App\Services\Media\Captions;

use App\Models\MediaItem;
use App\Services\IntegrationSettings;
use Illuminate\Support\Facades\Http;

class OpenSubtitlesCaptionProvider implements CaptionProvider
{
    public function search(MediaItem $item, array $languages): array
    {
        $key = app(IntegrationSettings::class)->get('opensubtitles_api_key', config('services.opensubtitles.api_key'));
        if (! is_string($key) || $key === '') {
            return [];
        }
        $headers = ['Api-Key' => $key, 'User-Agent' => config('services.opensubtitles.user_agent', 'Odissey v1')];
        $metadata = $item->metadata ?? [];
        $params = ['languages' => implode(',', $languages), 'query' => $metadata['series_title'] ?? $item->title, 'order_by' => 'download_count', 'order_direction' => 'desc'];
        if (! empty($metadata['tmdb_id'])) {
            $params['tmdb_id'] = $metadata['tmdb_id'];
        }
        if (($metadata['kind'] ?? '') === 'episode') {
            $params['season_number'] = $metadata['season_number'];
            $params['episode_number'] = $metadata['episode_number'];
        }
        $response = Http::acceptJson()->withHeaders($headers)->timeout(15)->get('https://api.opensubtitles.com/api/v1/subtitles', $params);
        if (! $response->successful()) {
            return [];
        }
        $found = [];
        foreach ($response->json('data', []) as $entry) {
            $attributes = $entry['attributes'] ?? [];
            $language = strtolower($attributes['language'] ?? '');
            $fileId = $attributes['files'][0]['file_id'] ?? null;
            if ($language === '' || ! $fileId || isset($found[$language])) {
                continue;
            }
            $download = Http::acceptJson()->withHeaders($headers)->timeout(15)->post('https://api.opensubtitles.com/api/v1/download', ['file_id' => $fileId]);
            $url = $download->successful() ? $download->json('link') : null;
            if (! is_string($url) || ! str_starts_with($url, 'https://')) {
                continue;
            }
            $found[$language] = new CaptionCandidate('opensubtitles', (string) $fileId, $language, $attributes['release'] ?? strtoupper($language), $url, (bool) ($attributes['hearing_impaired'] ?? false), $headers);
        }

        return array_values($found);
    }
}
