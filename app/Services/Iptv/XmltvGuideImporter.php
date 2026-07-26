<?php

namespace App\Services\Iptv;

use App\Models\Iptv\EpgProgram;
use App\Models\Iptv\IptvProvider;
use App\Services\Iptv\Exceptions\SanitizedIptvException;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use SimpleXMLElement;
use Throwable;
use XMLReader;

class XmltvGuideImporter
{
    /**
     * XMLReader receives an in-memory document from the bounded fetcher. Its
     * decoded tree fragments can be several times larger than the wire
     * representation, so this hard ceiling must stay well below memory_limit.
     */
    private const MAX_XMLTV_BYTES = 8 * 1024 * 1024;

    private const MAX_XMLTV_CHANNELS = 100000;

    private const MAX_XMLTV_PROGRAMS = 200000;

    private const MAX_PROGRAM_XML_BYTES = 256 * 1024;

    public function __construct(
        private readonly BoundedIptvDocumentFetcher $documents,
        private readonly EpgGuideLimiter $guideLimiter,
    ) {}

    public function import(IptvProvider $provider): int
    {
        $url = $provider->config['xmltv_url'] ?? null;

        if (! is_string($url) || $url === '') {
            return 0;
        }

        $body = $this->documents->fetch(
            url: $url,
            allowInsecureHttp: (bool) $provider->allow_insecure_http,
            maxBytes: min(
                self::MAX_XMLTV_BYTES,
                max(1, (int) config('iptv.xmltv_max_bytes', self::MAX_XMLTV_BYTES)),
            ),
            timeoutSeconds: 60,
            unavailableCode: 'xmltv_invalid',
            invalidCode: 'xmltv_invalid',
            unavailableStatus: 422,
        );
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
        $channels = $provider->channels()
            ->whereNotNull('epg_channel_id')
            ->orderBy('id')
            ->limit($channelLimit)
            ->pluck('id', 'epg_channel_id');
        $syncToken = (string) Str::ulid();
        $reader = new XMLReader;
        $previousInternalErrors = libxml_use_internal_errors(true);
        libxml_clear_errors();

        try {
            if (preg_match('/<!DOCTYPE/i', $body) === 1) {
                throw new SanitizedIptvException('xmltv_invalid', 422);
            }

            if (! $reader->XML($body, 'UTF-8', LIBXML_NONET | LIBXML_COMPACT)) {
                throw new SanitizedIptvException('xmltv_invalid', 422);
            }

            return DB::transaction(function () use (
                $channels,
                $programLimit,
                $provider,
                $reader,
                $syncToken,
            ): int {
                $count = 0;
                $scanned = 0;
                $capped = false;

                while ($reader->read()) {
                    if (
                        $reader->nodeType !== XMLReader::ELEMENT
                        || $reader->name !== 'programme'
                    ) {
                        continue;
                    }

                    if (++$scanned > $programLimit) {
                        $capped = true;

                        // Keep parsing through a clean EOF so malformed input
                        // after the persistence cap still rolls back the import.
                        continue;
                    }

                    $channelId = $channels->get(
                        $reader->getAttribute('channel'),
                    );

                    if (! $channelId) {
                        continue;
                    }

                    $program = $this->programFromReader($reader, $channelId);

                    if ($program === null) {
                        continue;
                    }

                    EpgProgram::query()->updateOrCreate(
                        [
                            'channel_id' => $channelId,
                            'fingerprint' => $program['fingerprint'],
                        ],
                        [
                            'iptv_provider_id' => $provider->id,
                            'sync_token' => $syncToken,
                            'title' => $program['title'],
                            'description' => $program['description'],
                            'category' => $program['category'],
                            'starts_at' => $program['starts_at'],
                            'ends_at' => $program['ends_at'],
                        ],
                    );
                    $count++;
                }

                if (libxml_get_errors() !== []) {
                    throw new SanitizedIptvException('xmltv_invalid', 422);
                }

                if (! $capped) {
                    $this->reconcile($provider, $syncToken);
                }

                $this->guideLimiter->enforce($provider);

                return $count;
            });
        } catch (SanitizedIptvException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new SanitizedIptvException('xmltv_invalid', 422);
        } finally {
            $reader->close();
            libxml_clear_errors();
            libxml_use_internal_errors($previousInternalErrors);
        }
    }

    /**
     * @return array{
     *   fingerprint: string,
     *   title: string,
     *   description: ?string,
     *   category: ?string,
     *   starts_at: CarbonImmutable,
     *   ends_at: CarbonImmutable
     * }|null
     */
    private function programFromReader(
        XMLReader $reader,
        int $channelId,
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
            || $end <= CarbonImmutable::now()->subDay()
            || $start >= CarbonImmutable::now()->addDays(14)
        ) {
            return null;
        }

        return [
            'fingerprint' => hash(
                'sha256',
                "{$channelId}|{$start->timestamp}|{$end->timestamp}|{$title}",
            ),
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

    private function reconcile(
        IptvProvider $provider,
        string $syncToken,
    ): void {
        $now = CarbonImmutable::now();

        EpgProgram::query()
            ->where('iptv_provider_id', $provider->id)
            ->where('ends_at', '>', $now)
            ->where(function ($query) use ($syncToken): void {
                $query->whereNull('sync_token')
                    ->orWhere('sync_token', '!=', $syncToken);
            })
            ->delete();

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
}
