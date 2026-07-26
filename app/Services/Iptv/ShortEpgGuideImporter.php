<?php

namespace App\Services\Iptv;

use App\Models\Iptv\Channel;
use App\Models\Iptv\EpgProgram;
use App\Models\Iptv\IptvProvider;
use App\Services\Iptv\Contracts\GuideImporter;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

class ShortEpgGuideImporter implements GuideImporter
{
    private const MAX_CHANNELS_PER_BATCH = 20;

    public function __construct(
        private readonly XtreamClient $client,
        private readonly EpgGuideLimiter $guideLimiter,
    ) {}

    public function import(
        IptvProvider $provider,
        int $channelLimit,
        int $afterChannelId = 0,
    ): GuideImportResult {
        $this->client->authenticate($provider);
        $batchSize = max(1, min($channelLimit, self::MAX_CHANNELS_PER_BATCH));
        $programLimit = max(1, min((int) config('iptv.guide_program_limit'), 10));
        $channels = Channel::query()
            ->where('iptv_provider_id', $provider->id)
            ->where('is_active', true)
            ->where('id', '>', max(0, $afterChannelId))
            ->orderBy('id')
            ->limit($batchSize + 1)
            ->get();
        $hasMore = $channels->count() > $batchSize;
        $channels = $channels->take($batchSize);

        $seen = 0;

        foreach ($channels as $channel) {
            $listings = $this->client->shortEpg(
                $provider,
                $channel->external_id,
                $programLimit,
            );

            $seen += $this->persistListings($provider, $channel, $listings);
        }

        EpgProgram::query()
            ->where('iptv_provider_id', $provider->id)
            ->where(function ($query): void {
                $query
                    ->where('ends_at', '<', now()->subDay())
                    ->orWhere('starts_at', '>', now()->addDays(7));
            })
            ->delete();
        $this->guideLimiter->enforce($provider);

        return new GuideImportResult(
            programsImported: $seen,
            lastChannelId: (int) ($channels->last()?->id ?? $afterChannelId),
            hasMore: $hasMore,
        );
    }

    /**
     * @param  array<int, array<string, mixed>>  $listings
     */
    private function persistListings(
        IptvProvider $provider,
        Channel $channel,
        array $listings,
    ): int {
        return DB::transaction(function () use ($provider, $channel, $listings): int {
            $count = 0;

            foreach ($listings as $listing) {
                if (! is_array($listing)) {
                    continue;
                }

                $startsAt = $this->timestamp(
                    $listing['start_timestamp'] ?? $listing['start'] ?? null,
                );
                $endsAt = $this->timestamp(
                    $listing['stop_timestamp'] ?? $listing['end'] ?? null,
                );

                if ($startsAt === null || $endsAt === null || $endsAt <= $startsAt) {
                    continue;
                }

                $title = $this->text($listing['title'] ?? null) ?? 'Untitled program';
                $description = $this->text($listing['description'] ?? null, 10000);
                $category = $this->text($listing['category'] ?? null);
                $fingerprint = hash('sha256', implode('|', [
                    $channel->external_id,
                    $startsAt->getTimestamp(),
                    $endsAt->getTimestamp(),
                    $title,
                ]));

                EpgProgram::query()->updateOrCreate(
                    [
                        'channel_id' => $channel->id,
                        'fingerprint' => $fingerprint,
                    ],
                    [
                        'iptv_provider_id' => $provider->id,
                        'upstream_event_id' => $this->text(
                            $listing['id'] ?? $listing['epg_id'] ?? null,
                            255,
                        ),
                        'title' => $title,
                        'description' => $description,
                        'category' => $category,
                        'starts_at' => $startsAt,
                        'ends_at' => $endsAt,
                    ],
                );

                $count++;
            }

            return $count;
        });
    }

    private function timestamp(mixed $value): ?CarbonImmutable
    {
        try {
            if (is_int($value) || (is_string($value) && ctype_digit($value))) {
                return CarbonImmutable::createFromTimestampUTC((int) $value);
            }

            if (is_string($value) && trim($value) !== '') {
                return CarbonImmutable::parse($value, 'UTC');
            }
        } catch (Throwable) {
            return null;
        }

        return null;
    }

    private function text(mixed $value, int $limit = 255): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);
        $decoded = base64_decode($value, true);

        if ($decoded !== false && $decoded !== '' && preg_match('//u', $decoded) === 1) {
            $value = $decoded;
        }

        $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value);

        return is_string($value) && $value !== ''
            ? Str::limit($value, $limit, '')
            : null;
    }
}
