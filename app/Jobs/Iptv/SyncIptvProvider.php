<?php

namespace App\Jobs\Iptv;

use App\Models\Iptv\IptvProvider;
use App\Services\Iptv\Exceptions\SanitizedIptvException;
use App\Services\Iptv\ProviderCatalogSynchronizer;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SyncIptvProvider implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $timeout = 300;

    public int $tries = 3;

    public int $uniqueFor = 300;

    public function __construct(
        public readonly int $providerId,
    ) {
        $this->onQueue('iptv-sync');
    }

    public function uniqueId(): string
    {
        return (string) $this->providerId;
    }

    public function handle(ProviderCatalogSynchronizer $synchronizer): void
    {
        $provider = IptvProvider::query()->find($this->providerId);

        if ($provider === null || ! $provider->enabled) {
            return;
        }

        try {
            $synchronizer->sync($provider);
        } catch (SanitizedIptvException $exception) {
            $provider->forceFill([
                'sync_status' => 'failed',
                'last_error_code' => $exception->errorCode,
            ])->save();

            throw $exception;
        }
    }
}
