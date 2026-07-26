<?php

namespace App\Services\Media\Captions;

use App\Models\MediaItem;
use App\Services\IntegrationSettings;
use App\Services\Media\ConfidentialJsonClient;

class SubdlCaptionProvider implements CaptionProvider
{
    public function __construct(
        private readonly ConfidentialJsonClient $http,
    ) {}

    public function search(MediaItem $item, array $languages): array
    {
        $key = app(IntegrationSettings::class)->get('subdl_api_key', config('services.subdl.api_key'));
        if (! is_string($key) || $key === '') {
            return [];
        }
        $metadata = $item->metadata ?? [];
        $params = [
            'api_key' => $key, 'languages' => implode(',', array_map('strtoupper', $languages)),
            'type' => ($metadata['kind'] ?? '') === 'episode' ? 'tv' : 'movie',
            'subs_per_page' => 30, 'unpack' => 1, 'client' => 'custom_integration',
        ];
        if (! empty($metadata['tmdb_id'])) {
            $params['tmdb_id'] = $metadata['tmdb_id'];
        } else {
            $params['film_name'] = $metadata['series_title'] ?? $item->title;
            $params['year'] = $metadata['year'] ?? null;
        }
        if (($metadata['kind'] ?? '') === 'episode') {
            $params['season_number'] = $metadata['season_number'];
            $params['episode_number'] = $metadata['episode_number'];
        }
        $response = $this->http->get(
            'https://api.subdl.com/api/v1/subtitles',
            array_filter($params, fn ($value) => $value !== null),
            [],
            ['api.subdl.com'],
        );
        if (($response['status'] ?? null) !== true) {
            return [];
        }
        $found = [];
        foreach ($response['subtitles'] ?? [] as $subtitle) {
            $files = $subtitle['unpack_files'] ?? [$subtitle];
            foreach ($files as $file) {
                if (($metadata['kind'] ?? '') === 'episode' && isset($file['episode']) && (int) $file['episode'] !== (int) $metadata['episode_number']) {
                    continue;
                }
                $language = strtolower($file['language'] ?? $subtitle['language'] ?? '');
                if ($language === '' || isset($found[$language])) {
                    continue;
                }
                $relative = $file['url'] ?? $subtitle['url'] ?? null;
                if (! is_string($relative) || ! str_starts_with($relative, '/subtitle/')) {
                    continue;
                }
                $external = (string) ($file['file_n_id'] ?? $subtitle['id'] ?? hash('sha256', $relative));
                $found[$language] = new CaptionCandidate('subdl', $external, $language, $file['release_name'] ?? $file['name'] ?? strtoupper($language), 'https://dl.subdl.com'.$relative, (bool) ($file['hi'] ?? $subtitle['hi'] ?? false));
            }
        }

        return array_values($found);
    }
}
