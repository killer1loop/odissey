<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Iptv\IptvProvider;
use App\Models\MediaItem;
use App\Models\MediaSource;
use App\Models\TranscodeSession;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AdminController extends Controller
{
    public function users(Request $request): JsonResponse
    {
        $this->authorizeAdmin($request);
        $users = User::query()
            ->orderByDesc('is_admin')
            ->orderBy('name')
            ->limit(500)
            ->get()
            ->map(fn (User $user): array => [
                'id' => (string) $user->getKey(),
                'name' => $user->name,
                'email' => $user->email,
                'isAdmin' => $user->isAdmin(),
                'isActive' => $user->isActive(),
                'disabledAt' => $user->disabled_at?->utc()->toIso8601String(),
                'createdAt' => $user->created_at->utc()->toIso8601String(),
            ]);

        return response()->json(['data' => $users], headers: [
            'Cache-Control' => 'private, max-age=15',
        ]);
    }

    public function providers(Request $request): JsonResponse
    {
        $this->authorizeAdmin($request);
        $providers = IptvProvider::query()
            ->withCount(['channels', 'groups'])
            ->orderBy('name')
            ->limit(500)
            ->get()
            ->map(fn (IptvProvider $provider): array => [
                'id' => (string) $provider->getKey(),
                'name' => $provider->name,
                'enabled' => (bool) $provider->enabled,
                'allowInsecureHttp' => (bool) $provider->allow_insecure_http,
                'syncStatus' => $provider->sync_status,
                'lastErrorCode' => $provider->last_error_code,
                'lastSyncedAt' => $provider->last_synced_at?->utc()
                    ->toIso8601String(),
                'lastGuideSyncedAt' => $provider->last_guide_synced_at?->utc()
                    ->toIso8601String(),
                'channelCount' => (int) $provider->channels_count,
                'groupCount' => (int) $provider->groups_count,
                'credentialsConfigured' => (
                    trim((string) $provider->username) !== ''
                    && trim((string) $provider->password) !== ''
                ),
            ]);

        return response()->json(['data' => $providers], headers: [
            'Cache-Control' => 'private, max-age=15',
        ]);
    }

    public function mediaSources(Request $request): JsonResponse
    {
        $this->authorizeAdmin($request);
        $sources = MediaSource::query()
            ->withCount('items')
            ->orderBy('name')
            ->limit(500)
            ->get()
            ->map(fn (MediaSource $source): array => [
                'id' => (string) $source->getKey(),
                'name' => $source->name,
                'type' => $source->type,
                'enabled' => (bool) $source->enabled,
                'allowPrivateNetwork' => (bool) $source->allow_private_network,
                'scanStatus' => $source->scan_status,
                'lastErrorCode' => $source->last_error_code,
                'lastScannedAt' => $source->last_scanned_at?->utc()
                    ->toIso8601String(),
                'itemCount' => (int) $source->items_count,
                'configurationPresent' => is_array($source->configuration)
                    && $source->configuration !== [],
                'capabilities' => [
                    'range' => (bool) (
                        $source->capabilities['range'] ?? false
                    ),
                ],
            ]);

        return response()->json(['data' => $sources], headers: [
            'Cache-Control' => 'private, max-age=15',
        ]);
    }

    public function jobs(Request $request): JsonResponse
    {
        $this->authorizeAdmin($request);
        $queued = DB::table('jobs')
            ->select('queue')
            ->selectRaw('COUNT(*) AS aggregate')
            ->groupBy('queue')
            ->pluck('aggregate', 'queue')
            ->map(fn (mixed $count): int => (int) $count);

        return response()->json([
            'queues' => $queued,
            'failedCount' => DB::table('failed_jobs')->count(),
            'transcodes' => [
                'pending' => TranscodeSession::query()
                    ->where('status', TranscodeSession::STATUS_PENDING)
                    ->count(),
                'processing' => TranscodeSession::query()
                    ->where('status', TranscodeSession::STATUS_PROCESSING)
                    ->count(),
                'failed' => TranscodeSession::query()
                    ->where('status', TranscodeSession::STATUS_FAILED)
                    ->count(),
            ],
        ], headers: ['Cache-Control' => 'no-store']);
    }

    public function system(Request $request): JsonResponse
    {
        $this->authorizeAdmin($request);

        return response()->json([
            'release' => (string) config('odissey.release'),
            'apiVersion' => (string) config(
                'native-client.api_max_version',
            ),
            'database' => 'sqlite',
            'counts' => [
                'users' => User::query()->count(),
                'mediaItems' => MediaItem::query()
                    ->whereNull('missing_at')
                    ->count(),
                'mediaSources' => MediaSource::query()->count(),
                'iptvProviders' => IptvProvider::query()->count(),
            ],
        ], headers: ['Cache-Control' => 'no-store']);
    }

    private function authorizeAdmin(Request $request): void
    {
        abort_unless($request->user()->isAdmin(), 403);
    }
}
