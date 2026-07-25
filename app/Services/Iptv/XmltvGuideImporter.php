<?php

namespace App\Services\Iptv;

use App\Models\Iptv\EpgProgram;
use App\Models\Iptv\IptvProvider;
use App\Services\Iptv\Exceptions\SanitizedIptvException;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use XMLReader;

class XmltvGuideImporter
{
    public function import(IptvProvider $provider): int
    {
        $url = $provider->config['xmltv_url'] ?? null;
        if (! is_string($url) || $url === '') {
            return 0;
        }
        app(UpstreamUrlGuard::class)->assertPublicTarget($url, (bool) $provider->allow_insecure_http);
        $response = Http::timeout(60)->maxRedirects(0)->get($url);
        if (! $response->successful() || strlen($response->body()) > config('iptv.xmltv_max_bytes')) {
            throw new SanitizedIptvException('xmltv_invalid', 422);
        }
        $reader = new XMLReader;
        if (! $reader->XML($response->body(), 'UTF-8', LIBXML_NONET | LIBXML_COMPACT)) {
            throw new SanitizedIptvException('xmltv_invalid', 422);
        }
        $channels = $provider->channels()->whereNotNull('epg_channel_id')->pluck('id', 'epg_channel_id');
        $count = 0;
        while ($reader->read()) {
            if ($reader->nodeType !== XMLReader::ELEMENT || $reader->name !== 'programme') {
                continue;
            }
            $channelId = $channels->get($reader->getAttribute('channel'));
            if (! $channelId) {
                $reader->next('programme');

                continue;
            }
            try {
                $start = $this->date($reader->getAttribute('start'));
                $end = $this->date($reader->getAttribute('stop'));
            } catch (\Throwable) {
                continue;
            }
            $node = new \SimpleXMLElement($reader->readOuterXml());
            $title = trim((string) ($node->title[0] ?? ''));
            if ($title === '' || $end <= now()->subDay() || $start >= now()->addDays(14)) {
                continue;
            }
            EpgProgram::updateOrCreate(
                ['channel_id' => $channelId, 'fingerprint' => hash('sha256', $channelId.'|'.$start->timestamp.'|'.$end->timestamp.'|'.$title)],
                ['iptv_provider_id' => $provider->id, 'title' => mb_substr($title, 0, 255), 'description' => mb_substr(trim((string) ($node->desc[0] ?? '')), 0, 10000) ?: null, 'category' => mb_substr(trim((string) ($node->category[0] ?? '')), 0, 255) ?: null, 'starts_at' => $start, 'ends_at' => $end],
            );
            if (++$count >= 250000) {
                break;
            }
        }
        $reader->close();
        EpgProgram::where('iptv_provider_id', $provider->id)->where('ends_at', '<', now()->subDay())->delete();

        return $count;
    }

    private function date(?string $value): CarbonImmutable
    {
        if (! $value || ! preg_match('/^(\d{14})\s*([+-]\d{4}|Z)?/', $value, $m)) {
            throw new \InvalidArgumentException;
        }

        return CarbonImmutable::createFromFormat('YmdHis O', $m[1].' '.(($m[2] ?? '') === 'Z' ? '+0000' : ($m[2] ?? '+0000')))->utc();
    }
}
