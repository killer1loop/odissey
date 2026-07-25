<?php

namespace App\Jobs\Iptv;

use App\Models\Iptv\IptvProvider;
use App\Services\Iptv\Exceptions\SanitizedIptvException;
use App\Services\Iptv\ShortEpgGuideImporter;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

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

    public function handle(ShortEpgGuideImporter $importer): void
    {
        $provider = IptvProvider::query()->find($this->providerId);

        if ($provider === null || ! $provider->enabled) {
            return;
        }

        try {
            $importer->import(
                $provider,
                $this->channelLimit ?? (int) config('iptv.guide_channel_limit'),
            );

            $provider->forceFill([
                'last_guide_synced_at' => now(),
                'last_error_code' => null,
            ])->save();
        } catch (SanitizedIptvException $exception) {
            $provider->forceFill([
                'last_error_code' => $exception->errorCode,
            ])->save();

            throw $exception;
        }
    }
}
