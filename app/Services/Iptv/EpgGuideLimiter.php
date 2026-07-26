<?php

namespace App\Services\Iptv;

use App\Models\Iptv\EpgProgram;
use App\Models\Iptv\IptvProvider;
use Illuminate\Database\Eloquent\Builder;

class EpgGuideLimiter
{
    private const MAX_PROVIDER_GUIDE_ROWS = 200000;

    public function limit(): int
    {
        return min(
            self::MAX_PROVIDER_GUIDE_ROWS,
            max(1, (int) config('iptv.provider_guide_max_rows', 100000)),
        );
    }

    public function enforce(IptvProvider $provider): void
    {
        $excess = EpgProgram::query()
            ->where('iptv_provider_id', $provider->id)
            ->count() - $this->limit();

        while ($excess > 0) {
            $ids = $this->providerPrograms($provider)
                ->where('ends_at', '<=', now())
                ->orderBy('ends_at')
                ->orderBy('id')
                ->limit(min(1000, $excess))
                ->pluck('id');

            if ($ids->isEmpty()) {
                $ids = $this->providerPrograms($provider)
                    ->orderByDesc('starts_at')
                    ->orderByDesc('id')
                    ->limit(min(1000, $excess))
                    ->pluck('id');
            }

            if ($ids->isEmpty()) {
                break;
            }

            EpgProgram::query()->whereKey($ids)->delete();
            $excess -= $ids->count();
        }
    }

    private function providerPrograms(IptvProvider $provider): Builder
    {
        return EpgProgram::query()
            ->where('iptv_provider_id', $provider->id);
    }
}
