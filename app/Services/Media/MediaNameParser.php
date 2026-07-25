<?php

namespace App\Services\Media;

class MediaNameParser
{
    /** @return array<string, mixed> */
    public function parse(string $path): array
    {
        $name = pathinfo(basename($path), PATHINFO_FILENAME);
        $clean = trim(preg_replace('/[._]+/', ' ', $name));
        if (preg_match('/^(.*?)\s+[Ss](\d{1,2})[Ee](\d{1,3})(?:\s*[-–]\s*|\s+)?(.*)$/u', $clean, $m)) {
            return [
                'kind' => 'episode',
                'title' => trim($m[4]) ?: 'Episode '.(int) $m[3],
                'series_title' => trim($m[1]),
                'season_number' => (int) $m[2],
                'episode_number' => (int) $m[3],
            ];
        }
        preg_match('/\b((?:19|20)\d{2})\b/', $clean, $year);
        $title = trim(preg_replace('/\s*\(?\b(?:19|20)\d{2}\b\)?.*$/', '', $clean));

        return ['kind' => 'movie', 'title' => $title ?: $clean, 'year' => isset($year[1]) ? (int) $year[1] : null];
    }
}
