<?php

namespace App\Services\Iptv;

use App\Models\Iptv\EpgProgram;
use App\Models\Iptv\IptvProvider;
use App\Services\Iptv\Exceptions\SanitizedIptvException;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use SimpleXMLElement;
use Throwable;
use XMLReader;

class XmltvGuideImporter
{
    private bool $lastImportCapped = false;

    /**
     * XMLReader receives an in-memory document from the bounded fetcher. Its
     * decoded tree fragments can be several times larger than the wire
     * representation, so this hard ceiling must stay well below memory_limit.
     */
    private const MAX_XMLTV_BYTES = 8 * 1024 * 1024;

    private const MAX_XTREAM_XMLTV_BYTES = 128 * 1024 * 1024;

    private const MAX_XMLTV_CHANNELS = 100000;

    private const MAX_XMLTV_PROGRAMS = 200000;

    private const MAX_XTREAM_XMLTV_PROGRAMS_SCANNED = 2000000;

    private const MAX_XTREAM_PROGRAMS_PER_CHANNEL = 48;

    private const MAX_XTREAM_HORIZON_HOURS = 48;

    private const MAX_PROGRAM_XML_BYTES = 256 * 1024;

    public function __construct(
        private readonly BoundedIptvDocumentFetcher $documents,
        private readonly EpgGuideLimiter $guideLimiter,
        private readonly UpstreamUrlGuard $urlGuard,
    ) {}

    public function import(IptvProvider $provider): int
    {
        $this->lastImportCapped = false;
        $url = $provider->config['xmltv_url'] ?? null;

        if (! is_string($url) || $url === '') {
            return 0;
        }

        return $this->importFromUrl(
            provider: $provider,
            url: $url,
            maxBytes: min(
                self::MAX_XMLTV_BYTES,
                max(1, (int) config('iptv.xmltv_max_bytes', self::MAX_XMLTV_BYTES)),
            ),
            distributeAcrossChannels: false,
        );
    }

    public function importXtream(IptvProvider $provider): int
    {
        $this->lastImportCapped = false;
        $baseUrl = $this->urlGuard->normalizeBaseUrl(
            $provider->base_url,
            (bool) $provider->allow_insecure_http,
        );
        $url = $baseUrl.'/xmltv.php?'.http_build_query([
            'username' => $provider->username,
            'password' => $provider->password,
        ], '', '&', PHP_QUERY_RFC3986);

        return $this->importFromUrl(
            provider: $provider,
            url: $url,
            maxBytes: min(
                self::MAX_XTREAM_XMLTV_BYTES,
                max(1, (int) config(
                    'iptv.xtream_xmltv_max_bytes',
                    self::MAX_XTREAM_XMLTV_BYTES,
                )),
            ),
            distributeAcrossChannels: true,
        );
    }

    private function importFromUrl(
        IptvProvider $provider,
        string $url,
        int $maxBytes,
        bool $distributeAcrossChannels,
    ): int {
        $path = $this->documents->fetchToTemporaryFile(
            url: $url,
            allowInsecureHttp: (bool) $provider->allow_insecure_http,
            maxBytes: $maxBytes,
            timeoutSeconds: 120,
            unavailableCode: 'xmltv_unavailable',
            invalidCode: 'xmltv_invalid',
            invalidStatus: 422,
        );

        try {
            return $this->importFile(
                $provider,
                $path,
                $distributeAcrossChannels,
            );
        } finally {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }

    private function importFile(
        IptvProvider $provider,
        string $path,
        bool $distributeAcrossChannels,
    ): int {
        $channelLimit = min(
            self::MAX_XMLTV_CHANNELS,
            max(1, (int) config('iptv.xmltv_max_channels', 50000)),
        );
        $guideRowLimit = $this->guideLimiter->limit();
        $programLimit = min(
            $guideRowLimit,
            self::MAX_XMLTV_PROGRAMS,
            max(1, (int) config('iptv.xmltv_max_programs', 100000)),
        );
        $scanLimit = $distributeAcrossChannels
            ? min(
                self::MAX_XTREAM_XMLTV_PROGRAMS_SCANNED,
                max(1, (int) config(
                    'iptv.xtream_xmltv_max_programs_scanned',
                    1000000,
                )),
            )
            : $programLimit;
        $programsPerChannel = $distributeAcrossChannels
            ? min(
                self::MAX_XTREAM_PROGRAMS_PER_CHANNEL,
                max(1, (int) config(
                    'iptv.xtream_xmltv_programs_per_channel',
                    24,
                )),
            )
            : PHP_INT_MAX;
        $windowStart = CarbonImmutable::now()->startOfHour();
        $windowEnd = $windowStart->addHours(
            $distributeAcrossChannels
                ? min(
                    self::MAX_XTREAM_HORIZON_HOURS,
                    max(1, (int) config(
                        'iptv.xtream_xmltv_horizon_hours',
                        24,
                    )),
                )
                : 24 * 14,
        );
        $channels = $provider->channels()
            ->where('is_active', true)
            ->whereNotNull('epg_channel_id')
            ->where('epg_channel_id', '!=', '')
            ->orderBy('id')
            ->limit($channelLimit)
            ->get(['id', 'epg_channel_id'])
            ->groupBy('epg_channel_id')
            ->map(
                fn ($variants): array => $variants
                    ->pluck('id')
                    ->map(fn ($id): int => (int) $id)
                    ->all(),
            );
        $syncToken = (string) Str::ulid();
        $reader = new XMLReader;
        $staging = tmpfile();
        $previousInternalErrors = libxml_use_internal_errors(true);
        libxml_clear_errors();

        try {
            if ($staging === false) {
                throw new SanitizedIptvException('xmltv_invalid', 422);
            }

            if ($this->containsUnsafeDoctype($path)) {
                throw new SanitizedIptvException('xmltv_invalid', 422);
            }

            if (! $reader->open($path, 'UTF-8', LIBXML_NONET | LIBXML_COMPACT)) {
                throw new SanitizedIptvException('xmltv_invalid', 422);
            }

            $count = 0;
            $scanned = 0;
            $capped = false;
            $channelCounts = [];

            while ($reader->read()) {
                if (
                    $reader->nodeType !== XMLReader::ELEMENT
                    || $reader->name !== 'programme'
                ) {
                    continue;
                }

                if (++$scanned > $scanLimit) {
                    $capped = true;

                    // Continue through a clean EOF before any database writes.
                    continue;
                }

                $channelIds = $channels->get(
                    $reader->getAttribute('channel'),
                );

                if (! is_array($channelIds) || $channelIds === []) {
                    continue;
                }

                $program = $this->programFromReader(
                    $reader,
                    $windowStart,
                    $windowEnd,
                );

                if ($program === null) {
                    continue;
                }

                foreach ($channelIds as $channelId) {
                    if (
                        $count >= $programLimit
                        || ($channelCounts[$channelId] ?? 0) >= $programsPerChannel
                    ) {
                        $capped = true;

                        continue;
                    }

                    $this->stageProgram($staging, [
                        'channel_id' => $channelId,
                        'fingerprint' => hash(
                            'sha256',
                            "{$channelId}|{$program['starts_at']->timestamp}|{$program['ends_at']->timestamp}|{$program['title']}",
                        ),
                        'iptv_provider_id' => $provider->id,
                        'sync_token' => $syncToken,
                        'title' => $program['title'],
                        'description' => $program['description'],
                        'category' => $program['category'],
                        'starts_at' => $program['starts_at']->format('Y-m-d H:i:s'),
                        'ends_at' => $program['ends_at']->format('Y-m-d H:i:s'),
                    ]);
                    $count++;
                    $channelCounts[$channelId] = ($channelCounts[$channelId] ?? 0) + 1;
                }
            }

            if (libxml_get_errors() !== []) {
                throw new SanitizedIptvException('xmltv_invalid', 422);
            }

            $this->persistStagedPrograms($staging);

            if (! $capped) {
                $this->reconcile(
                    $provider,
                    $syncToken,
                    array_map('intval', array_keys($channelCounts)),
                );
            }

            if ($count > 0) {
                $this->guideLimiter->enforce($provider);
            }
            $this->lastImportCapped = $capped;

            return $count;
        } catch (SanitizedIptvException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new SanitizedIptvException('xmltv_invalid', 422);
        } finally {
            $reader->close();
            if (is_resource($staging)) {
                fclose($staging);
            }
            libxml_clear_errors();
            libxml_use_internal_errors($previousInternalErrors);
        }
    }

    public function lastImportWasCapped(): bool
    {
        return $this->lastImportCapped;
    }

    /**
     * @param  resource  $staging
     * @param  array<string, int|string|null>  $row
     */
    private function stageProgram($staging, array $row): void
    {
        $payload = json_encode($row, JSON_THROW_ON_ERROR)."\n";
        $written = 0;

        while ($written < strlen($payload)) {
            $bytes = fwrite($staging, substr($payload, $written));

            if ($bytes === false || $bytes === 0) {
                throw new SanitizedIptvException('xmltv_invalid', 422);
            }

            $written += $bytes;
        }
    }

    /**
     * @param  resource  $staging
     */
    private function persistStagedPrograms($staging): void
    {
        rewind($staging);
        $batchSize = min(
            1000,
            max(25, (int) config('iptv.xmltv_write_batch_size', 250)),
        );
        $batch = [];
        $now = now();

        while (($line = fgets($staging)) !== false) {
            $row = json_decode($line, true, 32, JSON_THROW_ON_ERROR);

            if (! is_array($row)) {
                throw new SanitizedIptvException('xmltv_invalid', 422);
            }

            $batch[] = [
                ...$row,
                'created_at' => $now,
                'updated_at' => $now,
            ];

            if (count($batch) >= $batchSize) {
                $this->upsertPrograms($batch);
                $batch = [];
            }
        }

        if ($batch !== []) {
            $this->upsertPrograms($batch);
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function upsertPrograms(array $rows): void
    {
        EpgProgram::query()->upsert(
            $rows,
            ['channel_id', 'fingerprint'],
            [
                'iptv_provider_id',
                'sync_token',
                'title',
                'description',
                'category',
                'starts_at',
                'ends_at',
                'updated_at',
            ],
        );
    }

    /**
     * @return array{
     *   title: string,
     *   description: ?string,
     *   category: ?string,
     *   starts_at: CarbonImmutable,
     *   ends_at: CarbonImmutable
     * }|null
     */
    private function programFromReader(
        XMLReader $reader,
        CarbonImmutable $windowStart,
        CarbonImmutable $windowEnd,
    ): ?array {
        try {
            $start = $this->date($reader->getAttribute('start'));
            $end = $this->date($reader->getAttribute('stop'));
        } catch (Throwable) {
            return null;
        }

        try {
            $programXml = $reader->readOuterXml();

            if (
                $programXml === ''
                || strlen($programXml) > self::MAX_PROGRAM_XML_BYTES
            ) {
                throw new SanitizedIptvException('xmltv_invalid', 422);
            }

            $node = new SimpleXMLElement(
                $programXml,
                LIBXML_NONET | LIBXML_COMPACT,
            );
        } catch (Throwable) {
            throw new SanitizedIptvException('xmltv_invalid', 422);
        }

        $title = trim((string) ($node->title[0] ?? ''));

        if (
            $title === ''
            || $end <= $windowStart
            || $start >= $windowEnd
        ) {
            return null;
        }

        return [
            'title' => mb_substr($title, 0, 255),
            'description' => mb_substr(
                trim((string) ($node->desc[0] ?? '')),
                0,
                10000,
            ) ?: null,
            'category' => mb_substr(
                trim((string) ($node->category[0] ?? '')),
                0,
                255,
            ) ?: null,
            'starts_at' => $start,
            'ends_at' => $end,
        ];
    }

    /**
     * @param  list<int>  $observedChannelIds
     */
    private function reconcile(
        IptvProvider $provider,
        string $syncToken,
        array $observedChannelIds,
    ): void {
        $now = CarbonImmutable::now();

        if ($observedChannelIds !== []) {
            EpgProgram::query()
                ->where('iptv_provider_id', $provider->id)
                ->whereIn('channel_id', $observedChannelIds)
                ->where('ends_at', '>', $now)
                ->where(function ($query) use ($syncToken): void {
                    $query->whereNull('sync_token')
                        ->orWhere('sync_token', '!=', $syncToken);
                })
                ->delete();
        }

        EpgProgram::query()
            ->where('iptv_provider_id', $provider->id)
            ->where('ends_at', '<', $now->subDay())
            ->delete();
    }

    private function date(?string $value): CarbonImmutable
    {
        if (
            ! $value
            || ! preg_match('/^(\d{14})\s*([+-]\d{4}|Z)?/', $value, $matches)
        ) {
            throw new \InvalidArgumentException;
        }

        return CarbonImmutable::createFromFormat(
            'YmdHis O',
            $matches[1].' '.(($matches[2] ?? '') === 'Z'
                ? '+0000'
                : ($matches[2] ?? '+0000')),
        )->utc();
    }

    private function containsUnsafeDoctype(string $path): bool
    {
        $handle = fopen($path, 'rb');

        if ($handle === false) {
            throw new SanitizedIptvException('xmltv_invalid', 422);
        }

        $carry = '';

        try {
            while (! feof($handle)) {
                $chunk = fread($handle, 8192);

                if ($chunk === false) {
                    throw new SanitizedIptvException('xmltv_invalid', 422);
                }

                $candidate = $carry.$chunk;
                $candidate = preg_replace(
                    '/<!DOCTYPE\s+tv\s+SYSTEM\s+(["\'])xmltv\.dtd\1\s*>/i',
                    '',
                    $candidate,
                );

                if (
                    ! is_string($candidate)
                    || preg_match('/<!DOCTYPE/i', $candidate) === 1
                ) {
                    return true;
                }

                $carry = substr($candidate, -256);
            }
        } finally {
            fclose($handle);
        }

        return false;
    }
}
