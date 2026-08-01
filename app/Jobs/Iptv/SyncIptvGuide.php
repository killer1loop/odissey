<?php

namespace App\Jobs\Iptv;

use App\Models\Iptv\IptvProvider;
use App\Services\Iptv\Exceptions\SanitizedIptvException;
use App\Services\Iptv\XmltvGuideImporter;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class SyncIptvGuide implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $timeout = 480;

    public int $tries = 2;

    public int $uniqueFor = 600;

    public function __construct(
        public readonly int $providerId,
        public readonly ?int $channelLimit = null,
    ) {
        $this->onQueue('iptv-sync');
    }

    public function uniqueId(): string
    {
        return (string) $this->providerId;
    }

    public function handle(XmltvGuideImporter $xmltvImporter): void
    {
        $provider = IptvProvider::query()->find($this->providerId);

        if ($provider === null || ! $provider->enabled) {
            return;
        }

        if (
            ($provider->config['api'] ?? 'xtream') === 'm3u'
            && (
                ! is_string($provider->config['xmltv_url'] ?? null)
                || trim($provider->config['xmltv_url']) === ''
            )
        ) {
            $provider->forceFill([
                'last_guide_error_code' => 'guide_not_configured',
            ])->save();

            return;
        }

        try {
            if (($provider->config['api'] ?? 'xtream') === 'm3u') {
                $imported = $xmltvImporter->import($provider);
            } else {
                $imported = $xmltvImporter->importXtream($provider);
            }
            if ($imported === 0) {
                throw new SanitizedIptvException(
                    'xmltv_guide_empty',
                    422,
                );
            }

            $provider->forceFill([
                'last_guide_synced_at' => now(),
                'last_guide_error_code' => $xmltvImporter->lastImportWasCapped()
                    ? 'xmltv_guide_truncated'
                    : null,
            ])->save();
        } catch (Throwable $exception) {
            $provider->forceFill([
                'last_guide_error_code' => $this->errorCode($exception),
            ])->save();

            throw $exception;
        }
    }

    public function failed(?Throwable $exception): void
    {
        IptvProvider::query()
            ->whereKey($this->providerId)
            ->update([
                'last_guide_error_code' => $this->errorCode($exception),
            ]);
    }

    private function errorCode(?Throwable $exception): string
    {
        return $exception instanceof SanitizedIptvException
            ? $exception->errorCode
            : 'guide_sync_failed';
    }
}
