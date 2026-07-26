<?php

namespace App\Services\Media;

class TvmazeMetadataProvider
{
    public function __construct(
        private readonly ConfidentialJsonClient $http,
    ) {}

    /** @param array<string, mixed> $parsed @return array<string, mixed> */
    public function match(array $parsed): array
    {
        $kind = $parsed['kind'] ?? '';
        if (
            ! in_array($kind, ['episode', 'series'], true)
            || empty($parsed['series_title'])
        ) {
            return [];
        }
        $show = $this->http->get(
            'https://api.tvmaze.com/singlesearch/shows',
            ['q' => $parsed['series_title']],
            [],
            ['api.tvmaze.com'],
        );
        if ($show === null) {
            return [];
        }
        $episode = $kind === 'episode' && isset($show['id'])
            ? $this->http->get(
                'https://api.tvmaze.com/shows/'.(int) $show['id'].'/episodebynumber',
                [
                    'season' => $parsed['season_number'],
                    'number' => $parsed['episode_number'],
                ],
                [],
                ['api.tvmaze.com'],
            ) ?? []
            : [];
        $summary = strip_tags((string) ($episode['summary'] ?? $show['summary'] ?? ''));

        return array_filter([
            'provider' => 'tvmaze',
            'tvmaze_id' => $show['id'] ?? null,
            'tvmaze_episode_id' => $episode['id'] ?? null,
            'title' => $episode['name']
                ?? $show['name']
                ?? $parsed['title'],
            'series_title' => $show['name'] ?? $parsed['series_title'],
            'summary' => $summary ?: null,
            'year' => isset($show['premiered']) ? (int) substr($show['premiered'], 0, 4) : null,
            'rating' => $show['rating']['average'] ?? null,
            'genres' => $show['genres'] ?? [],
            'poster_url' => $show['image']['original'] ?? $show['image']['medium'] ?? null,
            'backdrop_url' => $episode['image']['original'] ?? null,
            'metadata_fetched_at' => now()->toIso8601String(),
        ], fn ($value) => $value !== null && $value !== '' && $value !== []);
    }
}
