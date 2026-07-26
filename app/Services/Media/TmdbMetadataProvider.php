<?php

namespace App\Services\Media;

use App\Services\IntegrationSettings;

class TmdbMetadataProvider
{
    public function __construct(
        private readonly ConfidentialJsonClient $http,
    ) {}

    /** @param array<string, mixed> $parsed @return array<string, mixed> */
    public function match(array $parsed): array
    {
        $token = app(IntegrationSettings::class)->get('tmdb_api_token', config('services.tmdb.token'));
        if (! is_string($token) || $token === '') {
            return [];
        }
        $isTv = ($parsed['kind'] ?? '') === 'episode';
        $query = $isTv ? $parsed['series_title'] : $parsed['title'];
        $params = ['query' => $query, 'include_adult' => 'false', 'language' => config('services.tmdb.language', 'en-US')];
        if (! $isTv && ! empty($parsed['year'])) {
            $params['primary_release_year'] = $parsed['year'];
        }
        $headers = ['Authorization' => 'Bearer '.$token];
        $search = $this->http->get(
            'https://api.themoviedb.org/3/search/'.($isTv ? 'tv' : 'movie'),
            $params,
            $headers,
            ['api.themoviedb.org'],
        );
        if ($search === null) {
            return [];
        }
        $hit = $search['results'][0] ?? null;
        if (! is_array($hit) || empty($hit['id'])) {
            return [];
        }
        $detail = $this->http->get(
            'https://api.themoviedb.org/3/'.($isTv ? 'tv' : 'movie').'/'.(int) $hit['id'],
            [
                'language' => config('services.tmdb.language', 'en-US'),
                'append_to_response' => 'external_ids,credits',
            ],
            $headers,
            ['api.themoviedb.org'],
        );
        if ($detail === null) {
            return [];
        }
        $d = $detail;

        return [
            'provider' => 'tmdb',
            'tmdb_id' => $d['id'],
            'title' => $d['title'] ?? $d['name'] ?? $query,
            'sort_title' => $d['original_title'] ?? $d['original_name'] ?? null,
            'summary' => $d['overview'] ?? null,
            'year' => (int) substr($d['release_date'] ?? $d['first_air_date'] ?? '', 0, 4) ?: null,
            'rating' => $d['vote_average'] ?? null,
            'genres' => array_values(array_filter(array_column($d['genres'] ?? [], 'name'))),
            'cast' => array_slice(array_values(array_filter(array_column($d['credits']['cast'] ?? [], 'name'))), 0, 12),
            'poster_url' => empty($d['poster_path']) ? null : 'https://image.tmdb.org/t/p/w500'.$d['poster_path'],
            'backdrop_url' => empty($d['backdrop_path']) ? null : 'https://image.tmdb.org/t/p/w1280'.$d['backdrop_path'],
            'metadata_fetched_at' => now()->toIso8601String(),
        ];
    }
}
