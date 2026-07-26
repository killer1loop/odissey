<?php

namespace App\Services\Iptv;

use App\Models\Iptv\Channel;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use JsonException;
use Throwable;

class ChannelLogoResolver
{
    private const CACHE_KEY = 'odissey:iptv:channel-logo-catalog:v1';

    private const SOURCE = 'iptv-org';

    /** @var array{by_id: array<string, array{url: string, channel_id: string}>, by_alias: array<string, array{url: string, channel_id: string}|null>, by_country_alias: array<string, array{url: string, channel_id: string}|null>}|null */
    private ?array $runtimeIndex = null;

    public function __construct(
        private readonly BoundedIptvDocumentFetcher $documents,
    ) {}

    /**
     * @param  array<int, array<string, mixed>>  $streams
     */
    public function resolve(
        array $streams,
        bool $forceRefresh = false,
    ): ChannelLogoResolution {
        if (! config('iptv.channel_logo_catalog_enabled', true)) {
            return new ChannelLogoResolution(false);
        }

        try {
            $index = $this->index($forceRefresh);
        } catch (Throwable $exception) {
            Log::notice('External channel logo catalog was unavailable.', [
                'source' => self::SOURCE,
                'exception' => $exception::class,
            ]);

            return new ChannelLogoResolution(false);
        }

        $matches = [];
        foreach ($streams as $stream) {
            if (! is_array($stream)) {
                continue;
            }
            $externalId = $this->scalarId($stream['stream_id'] ?? null);
            if ($externalId === null) {
                continue;
            }
            $match = $this->match(
                $index,
                $this->text($stream['epg_channel_id'] ?? null),
                $this->text($stream['name'] ?? null),
            );
            if ($match !== null) {
                $matches[$externalId] = $match;
            }
        }

        return new ChannelLogoResolution(true, $matches);
    }

    /**
     * Refresh the external catalog and replace artwork for all active
     * channels. Unmatched channels intentionally use the initials fallback.
     *
     * @return array{matched: int, unmatched: int}
     */
    public function refreshExistingChannels(): array
    {
        $matched = 0;
        $unmatched = 0;
        $forceRefresh = true;

        Channel::query()
            ->where('is_active', true)
            ->orderBy('id')
            ->chunkById(
                250,
                function ($channels) use (
                    &$forceRefresh,
                    &$matched,
                    &$unmatched,
                ): void {
                    $streams = $channels->map(fn (Channel $channel): array => [
                        'stream_id' => (string) $channel->external_id,
                        'epg_channel_id' => $channel->epg_channel_id,
                        'name' => $channel->name,
                    ])->all();
                    $resolution = $this->resolve($streams, $forceRefresh);
                    $forceRefresh = false;

                    if (! $resolution->available) {
                        throw new \RuntimeException(
                            'channel_logo_catalog_unavailable',
                        );
                    }

                    foreach ($channels as $channel) {
                        $match = $resolution->match(
                            (string) $channel->external_id,
                        );
                        $channel->forceFill([
                            'stream_icon' => $match['url'] ?? null,
                            'logo_source' => $match === null
                                ? null
                                : self::SOURCE,
                            'logo_channel_id' => $match['channel_id'] ?? null,
                        ])->saveQuietly();
                        $match === null ? $unmatched++ : $matched++;
                    }
                },
            );

        return compact('matched', 'unmatched');
    }

    /**
     * @return array{by_id: array<string, array{url: string, channel_id: string}>, by_alias: array<string, array{url: string, channel_id: string}|null>, by_country_alias: array<string, array{url: string, channel_id: string}|null>}
     */
    private function index(bool $forceRefresh): array
    {
        $store = Cache::store((string) config(
            'odissey.runtime_cache_store',
            'file',
        ));

        if ($forceRefresh) {
            $this->runtimeIndex = null;
            $store->forget(self::CACHE_KEY);
        }
        if ($this->runtimeIndex !== null) {
            return $this->runtimeIndex;
        }

        $hours = min(
            168,
            max(
                1,
                (int) config(
                    'iptv.channel_logo_catalog_cache_hours',
                    24,
                ),
            ),
        );

        return $this->runtimeIndex = $store->remember(
            self::CACHE_KEY,
            now()->addHours($hours),
            fn (): array => $this->downloadIndex(),
        );
    }

    /**
     * @return array{by_id: array<string, array{url: string, channel_id: string}>, by_alias: array<string, array{url: string, channel_id: string}|null>, by_country_alias: array<string, array{url: string, channel_id: string}|null>}
     */
    private function downloadIndex(): array
    {
        $maxBytes = min(
            64 * 1024 * 1024,
            max(
                1024 * 1024,
                (int) config(
                    'iptv.channel_logo_catalog_max_bytes',
                    64 * 1024 * 1024,
                ),
            ),
        );
        $maxRows = min(
            150000,
            max(
                1000,
                (int) config(
                    'iptv.channel_logo_catalog_max_rows',
                    100000,
                ),
            ),
        );
        $logos = $this->decodeList($this->documents->fetch(
            url: (string) config('iptv.channel_logo_logos_url'),
            allowInsecureHttp: false,
            maxBytes: $maxBytes,
            timeoutSeconds: 30,
            unavailableCode: 'channel_logo_catalog_unavailable',
            invalidCode: 'channel_logo_catalog_invalid',
        ), $maxRows);
        $bestLogos = [];

        foreach ($logos as $logo) {
            if (! is_array($logo) || ($logo['in_use'] ?? false) !== true) {
                continue;
            }
            $channelId = $this->text($logo['channel'] ?? null);
            $url = $this->secureUrl($logo['url'] ?? null);
            $format = strtoupper((string) ($logo['format'] ?? ''));
            if (
                $channelId === null
                || $url === null
                || ! in_array(
                    $format,
                    ['PNG', 'JPEG', 'WEBP', 'GIF'],
                    true,
                )
            ) {
                continue;
            }
            $candidate = [
                'url' => $url,
                'channel_id' => $channelId,
                'score' => $this->logoScore($logo),
            ];
            if (
                ! isset($bestLogos[$channelId])
                || $candidate['score'] > $bestLogos[$channelId]['score']
            ) {
                $bestLogos[$channelId] = $candidate;
            }
        }
        unset($logos);

        $channels = $this->decodeList($this->documents->fetch(
            url: (string) config('iptv.channel_logo_channels_url'),
            allowInsecureHttp: false,
            maxBytes: $maxBytes,
            timeoutSeconds: 30,
            unavailableCode: 'channel_logo_catalog_unavailable',
            invalidCode: 'channel_logo_catalog_invalid',
        ), $maxRows);
        $byId = [];
        $byAlias = [];
        $byCountryAlias = [];

        foreach ($channels as $channel) {
            if (
                ! is_array($channel)
                || ($channel['is_nsfw'] ?? false) === true
                || ! empty($channel['closed'])
            ) {
                continue;
            }
            $channelId = $this->text($channel['id'] ?? null);
            if ($channelId === null || ! isset($bestLogos[$channelId])) {
                continue;
            }
            $match = [
                'url' => $bestLogos[$channelId]['url'],
                'channel_id' => $channelId,
            ];
            $byId[strtolower($channelId)] = $match;
            $country = strtoupper((string) ($channel['country'] ?? ''));
            $names = [
                $channel['name'] ?? null,
                ...(
                    is_array($channel['alt_names'] ?? null)
                        ? $channel['alt_names']
                        : []
                ),
            ];
            foreach ($names as $name) {
                if (! is_string($name)) {
                    continue;
                }
                foreach ($this->nameVariants($name) as $alias) {
                    $this->addUniqueAlias(
                        $byAlias,
                        $alias,
                        $match,
                        $channelId,
                    );
                    if (preg_match('/^[A-Z]{2}$/', $country) === 1) {
                        $this->addUniqueAlias(
                            $byCountryAlias,
                            $country.':'.$alias,
                            $match,
                            $channelId,
                        );
                    }
                }
            }
        }

        return [
            'by_id' => $byId,
            'by_alias' => $byAlias,
            'by_country_alias' => $byCountryAlias,
        ];
    }

    /**
     * @param  array{by_id: array<string, array{url: string, channel_id: string}>, by_alias: array<string, array{url: string, channel_id: string}|null>, by_country_alias: array<string, array{url: string, channel_id: string}|null>}  $index
     * @return array{url: string, channel_id: string}|null
     */
    private function match(
        array $index,
        ?string $epgChannelId,
        ?string $name,
    ): ?array {
        if ($epgChannelId !== null) {
            $exact = $index['by_id'][strtolower($epgChannelId)] ?? null;
            if ($exact !== null) {
                return $exact;
            }
        }
        if ($name === null) {
            return null;
        }

        $country = $this->countryPrefix($name);
        foreach ($this->nameVariants($name) as $alias) {
            if ($country !== null) {
                $countryMatch = $index['by_country_alias'][
                    $country.':'.$alias
                ] ?? null;
                if ($countryMatch !== null) {
                    return $countryMatch;
                }
            }
            $match = $index['by_alias'][$alias] ?? null;
            if ($match !== null) {
                return $match;
            }
        }

        return null;
    }

    /**
     * @param  array<string, array{url: string, channel_id: string}|null>  $aliases
     * @param  array{url: string, channel_id: string}  $match
     */
    private function addUniqueAlias(
        array &$aliases,
        string $alias,
        array $match,
        string $channelId,
    ): void {
        if (
            ! array_key_exists($alias, $aliases)
            || ($aliases[$alias]['channel_id'] ?? null) === $channelId
        ) {
            $aliases[$alias] = $match;

            return;
        }

        // Ambiguous aliases must never guess a channel.
        $aliases[$alias] = null;
    }

    private function countryPrefix(string $name): ?string
    {
        if (
            preg_match(
                '/^\s*([A-Za-z]{2,3})\s*[:|•]\s*/u',
                $name,
                $matches,
            ) !== 1
        ) {
            return null;
        }

        return match (strtoupper($matches[1])) {
            'USA' => 'US',
            'GBR' => 'UK',
            default => strlen($matches[1]) === 2
                ? strtoupper($matches[1])
                : null,
        };
    }

    /** @return array<int, string> */
    private function nameVariants(string $name): array
    {
        $variants = [$name];
        $withoutPrefix = preg_replace(
            '/^\s*[\p{L}\p{N}]{2,4}\s*[:|•]\s*/u',
            '',
            $name,
        );
        if (is_string($withoutPrefix)) {
            $variants[] = $withoutPrefix;
        }
        foreach ([...$variants] as $variant) {
            $variants[] = preg_replace(
                '/(?:\s*[-|]\s*)?(?:UHD|FHD|FULL\s*HD|HD|SD|4K|H\.?26[45]|HEVC)\s*$/iu',
                '',
                $variant,
            ) ?? $variant;
        }

        return array_values(array_unique(array_filter(array_map(
            function (string $variant): string {
                $variant = Str::ascii(Str::lower($variant));

                return preg_replace('/[^a-z0-9]+/', '', $variant) ?? '';
            },
            $variants,
        ), fn (string $variant): bool => strlen($variant) >= 2)));
    }

    /**
     * @param  array<string, mixed>  $logo
     */
    private function logoScore(array $logo): int
    {
        $tags = is_array($logo['tags'] ?? null)
            ? array_map('strtolower', $logo['tags'])
            : [];

        return (($logo['feed'] ?? null) === null ? 1000000 : 0)
            + (in_array('horizontal', $tags, true) ? 100000 : 0)
            + (in_array('transparent', $tags, true) ? 10000 : 0)
            + min(9999, max(0, (int) ($logo['width'] ?? 0)));
    }

    /**
     * @return array<int, mixed>
     */
    private function decodeList(string $json, int $maxRows): array
    {
        try {
            $decoded = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new \RuntimeException('channel_logo_catalog_invalid');
        }
        if (! is_array($decoded) || ! array_is_list($decoded)) {
            throw new \RuntimeException('channel_logo_catalog_invalid');
        }
        if (count($decoded) > $maxRows) {
            throw new \RuntimeException('channel_logo_catalog_row_limit');
        }

        return $decoded;
    }

    private function scalarId(mixed $value): ?string
    {
        if (! is_int($value) && ! is_string($value)) {
            return null;
        }
        $value = trim((string) $value);

        return $value === '' || strlen($value) > 255 ? null : $value;
    }

    private function text(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }
        $value = trim((string) $value);

        return $value === '' || mb_strlen($value) > 255 ? null : $value;
    }

    private function secureUrl(mixed $value): ?string
    {
        if (! is_string($value) || strlen($value) > 2048) {
            return null;
        }
        try {
            $parts = parse_url(trim($value));

            return is_array($parts)
                && strtolower((string) ($parts['scheme'] ?? '')) === 'https'
                && ! empty($parts['host'])
                && ! isset($parts['user'])
                && ! isset($parts['pass'])
                && ! isset($parts['fragment'])
                    ? trim($value)
                    : null;
        } catch (Throwable) {
            return null;
        }
    }
}
