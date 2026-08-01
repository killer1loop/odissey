<?php

namespace App\Services\Media;

use App\Models\MediaItem;
use App\Models\User;
use Illuminate\Http\Request;

class MediaArtworkAvailability
{
    private const REQUEST_KEY = 'nativeMediaArtworkAvailability';

    public function __construct(
        private readonly MediaArtworkResolver $resolver,
    ) {}

    /**
     * Resolve artwork once for a complete API result set and cache only
     * booleans on the current request. This avoids per-card parent queries.
     *
     * @param  iterable<int, MediaItem>  $items
     */
    public function prepare(Request $request, iterable $items): void
    {
        $user = $request->user();
        if (! $user instanceof User) {
            return;
        }

        $items = collect($items)
            ->filter(fn (mixed $item): bool => $item instanceof MediaItem)
            ->unique(fn (MediaItem $item): string => (string) $item->getKey())
            ->values();
        $availability = $this->map($request);
        $pending = $items->reject(
            fn (MediaItem $item): bool => array_key_exists(
                (string) $item->getKey(),
                $availability,
            ),
        );
        if ($pending->isEmpty()) {
            return;
        }

        foreach (['poster', 'backdrop'] as $kind) {
            $resolved = $this->resolver->resolve($pending, $user, $kind);
            foreach ($pending as $item) {
                $candidate = $resolved->get(
                    (string) $item->getKey(),
                    $item,
                );
                $availability[(string) $item->getKey()][$kind] = (
                    $candidate instanceof MediaItem
                    && $this->resolver->isAvailable($candidate, $kind)
                );
            }
        }

        $request->attributes->set(self::REQUEST_KEY, $availability);
    }

    public function available(
        Request $request,
        MediaItem $item,
        string $kind,
    ): bool {
        $this->prepare($request, [$item]);

        return (bool) (
            $this->map($request)[(string) $item->getKey()][$kind] ?? false
        );
    }

    /**
     * @return array<string, array{poster?: bool, backdrop?: bool}>
     */
    private function map(Request $request): array
    {
        $value = $request->attributes->get(self::REQUEST_KEY);

        return is_array($value) ? $value : [];
    }
}
