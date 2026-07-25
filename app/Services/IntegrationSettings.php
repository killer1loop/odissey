<?php

namespace App\Services;

use App\Models\IntegrationSetting;
use Illuminate\Support\Facades\Schema;

class IntegrationSettings
{
    public function get(string $key, mixed $fallback = null): mixed
    {
        if (! Schema::hasTable('integration_settings')) {
            return $fallback;
        }

        return IntegrationSetting::query()->find($key)?->value ?? $fallback;
    }

    public function has(string $key, mixed $fallback = null): bool
    {
        $value = $this->get($key, $fallback);

        return is_string($value) && $value !== '';
    }
}
