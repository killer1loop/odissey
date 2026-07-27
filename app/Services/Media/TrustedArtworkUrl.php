<?php

namespace App\Services\Media;

class TrustedArtworkUrl
{
    private const TRUSTED_HOSTS = [
        'image.tmdb.org',
        'static.tvmaze.com',
    ];

    private const TMDB_PAGE_HOSTS = [
        'media.themoviedb.org',
        'www.themoviedb.org',
    ];

    public function normalize(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);
        if (
            $value === ''
            || strlen($value) > 2048
            || preg_match('/[\x00-\x1F\x7F]/', $value) === 1
        ) {
            return null;
        }

        $parts = parse_url($value);
        if (
            ! is_array($parts)
            || ! in_array(
                strtolower((string) ($parts['scheme'] ?? '')),
                ['http', 'https'],
                true,
            )
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['port'])
        ) {
            return null;
        }

        $host = strtolower(rtrim((string) ($parts['host'] ?? ''), '.'));
        $path = (string) ($parts['path'] ?? '');
        if ($host === '' || $path === '' || ! str_starts_with($path, '/')) {
            return null;
        }

        if (in_array($host, self::TMDB_PAGE_HOSTS, true)) {
            if (! str_starts_with($path, '/t/p/')) {
                return null;
            }
            $host = 'image.tmdb.org';
        } elseif (! in_array($host, self::TRUSTED_HOSTS, true)) {
            return null;
        }

        $query = isset($parts['query']) && $parts['query'] !== ''
            ? '?'.$parts['query']
            : '';

        return 'https://'.$host.$path.$query;
    }

    public function first(mixed $value): ?string
    {
        if (is_string($value)) {
            return $this->normalize($value);
        }

        if (! is_array($value)) {
            return null;
        }

        foreach ($value as $candidate) {
            $url = $this->normalize($candidate);
            if ($url !== null) {
                return $url;
            }
        }

        return null;
    }
}
