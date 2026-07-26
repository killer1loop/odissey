<?php

namespace App\Jobs\Iptv;

use App\Models\Iptv\IptvProvider;
use App\Services\Iptv\Exceptions\SanitizedIptvException;
use App\Services\Iptv\IptvImportMemoryBudget;
use App\Services\Iptv\ProviderCatalogSynchronizer;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

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

    public function handle(
        ProviderCatalogSynchronizer $synchronizer,
        IptvImportMemoryBudget $memory,
    ): void {
        $provider = IptvProvider::query()->find($this->providerId);

        if ($provider === null || ! $provider->enabled) {
            return;
        }

        $memory->apply();

        try {
            $synchronizer->sync($provider);
        } catch (Throwable $exception) {
            $provider->forceFill([
                'sync_status' => 'failed',
                'last_error_code' => $exception instanceof SanitizedIptvException
                    ? $exception->errorCode
                    : 'provider_sync_failed',
            ])->save();

            throw $exception;
        }
    }

    public function failed(?Throwable $exception): void
    {
        IptvProvider::query()
            ->whereKey($this->providerId)
            ->update([
                'sync_status' => 'failed',
                'last_error_code' => $exception instanceof SanitizedIptvException
                    ? $exception->errorCode
                    : 'provider_sync_failed',
            ]);
    }
}
