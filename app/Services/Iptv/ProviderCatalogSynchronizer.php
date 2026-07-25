<?php

namespace App\Services\Iptv;

use App\Models\Iptv\Channel;
use App\Models\Iptv\ChannelGroup;
use App\Models\Iptv\IptvProvider;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ProviderCatalogSynchronizer
{
    public function __construct(
        private readonly XtreamClient $client,
    ) {}

    /**
     * @return array{groups: int, channels: int}
     */
    public function sync(IptvProvider $provider): array
    {
        $provider->forceFill([
            'sync_status' => 'syncing',
            'last_error_code' => null,
        ])->save();

        $this->client->authenticate($provider);
        $categories = $this->client->categories($provider);
        $streams = $this->client->liveStreams($provider);
        $now = now();
        $groupRows = [];

        foreach ($categories as $position => $category) {
            unset($categories[$position]);

            if (! is_array($category)) {
                continue;
            }

            $externalId = $this->scalarId($category['category_id'] ?? null);
            $name = $this->cleanText($category['category_name'] ?? null);

            if ($externalId === null || $name === null) {
                continue;
            }

            $groupRows[$externalId] = [
                'iptv_provider_id' => $provider->id,
                'external_id' => $externalId,
                'name' => $name,
                'sort_order' => (int) $position,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        $channelRows = [];

        foreach ($streams as $position => $stream) {
            unset($streams[$position]);

            if (! is_array($stream)) {
                continue;
            }

            $externalId = $this->scalarId($stream['stream_id'] ?? null);
            $name = $this->cleanText($stream['name'] ?? null);

            if ($externalId === null || $name === null) {
                continue;
            }

            $channelRows[$externalId] = [
                'iptv_provider_id' => $provider->id,
                'external_id' => $externalId,
                '_category_external_id' => $this->scalarId($stream['category_id'] ?? null),
                'epg_channel_id' => $this->nullableText($stream['epg_channel_id'] ?? null),
                'name' => $name,
                'channel_number' => $this->nullableText($stream['num'] ?? null, 64),
                'stream_icon' => $this->encryptNullable(
                    $this->nullableUrl($stream['stream_icon'] ?? null),
                ),
                'stream_extension' => 'm3u8',
                'metadata' => Crypt::encryptString(json_encode([
                    'archive' => (bool) ($stream['tv_archive'] ?? false),
                    'added' => $this->nullableText($stream['added'] ?? null, 64),
                ], JSON_THROW_ON_ERROR)),
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        $result = DB::transaction(function () use (
            $provider,
            &$groupRows,
            &$channelRows,
        ): array {
            ChannelGroup::query()
                ->where('iptv_provider_id', $provider->id)
                ->update(['is_active' => false]);

            Channel::query()
                ->where('iptv_provider_id', $provider->id)
                ->update(['is_active' => false]);

            $groupCount = count($groupRows);
            $chunk = [];

            foreach ($groupRows as $externalId => $groupRow) {
                $chunk[] = $groupRow;
                unset($groupRows[$externalId]);

                if (count($chunk) === 250) {
                    $this->upsertGroups($chunk);
                    $chunk = [];
                }
            }

            if ($chunk !== []) {
                $this->upsertGroups($chunk);
            }

            $groups = ChannelGroup::query()
                ->where('iptv_provider_id', $provider->id)
                ->where('is_active', true)
                ->pluck('id', 'external_id');

            $channelCount = count($channelRows);
            $chunk = [];

            foreach ($channelRows as $externalId => $channelRow) {
                $categoryId = $channelRow['_category_external_id'];
                unset($channelRow['_category_external_id']);
                $channelRow['channel_group_id'] = $categoryId === null
                    ? null
                    : $groups->get($categoryId);
                $chunk[] = $channelRow;
                unset($channelRows[$externalId]);

                if (count($chunk) === 250) {
                    $this->upsertChannels($chunk);
                    $chunk = [];
                }
            }

            if ($chunk !== []) {
                $this->upsertChannels($chunk);
            }

            return [
                'groups' => $groupCount,
                'channels' => $channelCount,
            ];
        });

        $provider->forceFill([
            'sync_status' => 'ready',
            'last_error_code' => null,
            'last_synced_at' => now(),
        ])->save();

        return $result;
    }

    private function scalarId(mixed $value): ?string
    {
        if (! is_string($value) && ! is_int($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' || mb_strlen($value) > 255 ? null : $value;
    }

    private function cleanText(mixed $value, int $limit = 255): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $value = preg_replace('/[\x00-\x1F\x7F]/u', '', trim((string) $value));

        if (! is_string($value) || $value === '') {
            return null;
        }

        return Str::limit($value, $limit, '');
    }

    private function nullableText(mixed $value, int $limit = 255): ?string
    {
        return $this->cleanText($value, $limit);
    }

    private function nullableUrl(mixed $value): ?string
    {
        if (! is_string($value) || mb_strlen($value) > 2048) {
            return null;
        }

        $parts = parse_url(trim($value));

        if (
            ! is_array($parts)
            || ! in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true)
            || empty($parts['host'])
        ) {
            return null;
        }

        return trim($value);
    }

    private function encryptNullable(?string $value): ?string
    {
        return $value === null ? null : Crypt::encryptString($value);
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function upsertGroups(array $rows): void
    {
        ChannelGroup::query()->upsert(
            $rows,
            ['iptv_provider_id', 'external_id'],
            ['name', 'sort_order', 'is_active', 'updated_at'],
        );
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function upsertChannels(array $rows): void
    {
        Channel::query()->upsert(
            $rows,
            ['iptv_provider_id', 'external_id'],
            [
                'channel_group_id',
                'epg_channel_id',
                'name',
                'channel_number',
                'stream_icon',
                'stream_extension',
                'metadata',
                'is_active',
                'updated_at',
            ],
        );
    }
}
