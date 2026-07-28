<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;

class DiscoveryController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $payload = [
            'product' => 'Odissey',
            'serverId' => $this->serverId(),
            'serverVersion' => (string) config('odissey.release'),
            'setupRequired' => ! User::query()->exists(),
            'api' => [
                'minimumVersion' => (string) config(
                    'native-client.api_min_version',
                ),
                'maximumVersion' => (string) config(
                    'native-client.api_max_version',
                ),
                'baseUrl' => url('/api/v1'),
            ],
            'features' => [
                'admin',
                'catalog',
                'favorites',
                'history',
                'liveTv',
                'music',
                'playbackNegotiation',
                'profiles',
                'search',
            ],
            'serverTime' => now()->utc()->toIso8601String(),
        ];

        return response()->json($payload, headers: [
            'Cache-Control' => 'public, max-age=60',
        ]);
    }

    private function serverId(): string
    {
        $key = (string) config('app.key');
        $material = $key !== '' ? $key : (string) config('app.url');

        return substr(hash_hmac('sha256', 'odissey-server', $material), 0, 32);
    }
}
