<?php

namespace App\Services\Media;

class ArtworkMetadataMerger
{
    /**
     * Preserve cached artwork across catalog refreshes unless its source URL
     * genuinely changed.
     *
     * @param  array<string, mixed>  $existing
     * @param  array<string, mixed>  $incoming
     * @return array<string, mixed>
     */
    public function merge(array $existing, array $incoming): array
    {
        foreach (['poster', 'backdrop'] as $kind) {
            $urlKey = $kind.'_url';
            $cachedKey = $kind.'_cached';
            $incomingUrl = $incoming[$urlKey] ?? null;
            $hasIncomingUrl = is_string($incomingUrl)
                && trim($incomingUrl) !== '';

            if (! $hasIncomingUrl) {
                if (array_key_exists($urlKey, $existing)) {
                    $incoming[$urlKey] = $existing[$urlKey];
                } else {
                    unset($incoming[$urlKey]);
                }

                $this->preserveCachedFlag(
                    $existing,
                    $incoming,
                    $cachedKey,
                );

                continue;
            }

            if (($existing[$urlKey] ?? null) === $incomingUrl) {
                $this->preserveCachedFlag(
                    $existing,
                    $incoming,
                    $cachedKey,
                );

                continue;
            }

            $incoming[$cachedKey] = false;
        }

        return $incoming;
    }

    /**
     * @param  array<string, mixed>  $existing
     * @param  array<string, mixed>  $incoming
     */
    private function preserveCachedFlag(
        array $existing,
        array &$incoming,
        string $cachedKey,
    ): void {
        if (array_key_exists($cachedKey, $existing)) {
            $incoming[$cachedKey] = $existing[$cachedKey];
        } else {
            unset($incoming[$cachedKey]);
        }
    }
}
