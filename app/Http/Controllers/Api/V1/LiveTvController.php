<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\ChannelResource;
use App\Http\Resources\Api\V1\EpgProgramResource;
use App\Jobs\Iptv\SyncIptvGuide;
use App\Models\Iptv\Channel;
use App\Models\Iptv\ChannelFavorite;
use App\Models\Iptv\ChannelGroup;
use App\Models\Iptv\EpgProgram;
use App\Models\Iptv\IptvProvider;
use App\Services\Api\AdminAuditService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\CursorPaginator;

class LiveTvController extends Controller
{
    public function groups(Request $request): JsonResponse
    {
        $groups = ChannelGroup::query()
            ->where('is_active', true)
            ->whereHas(
                'provider',
                fn (Builder $query) => $query->where('enabled', true),
            )
            ->whereHas(
                'channels',
                fn (Builder $query) => $query->where('is_active', true),
            )
            ->withCount([
                'channels' => fn (Builder $query) => $query->where(
                    'is_active',
                    true,
                ),
            ])
            ->orderBy('sort_order')
            ->orderBy('name')
            ->limit(500)
            ->get()
            ->map(fn (ChannelGroup $group): array => [
                'id' => (string) $group->getKey(),
                'name' => $group->name,
                'channelCount' => (int) $group->channels_count,
            ]);

        return response()->json(['data' => $groups], headers: [
            'Cache-Control' => 'private, max-age=60',
        ]);
    }

    public function channels(Request $request): JsonResponse
    {
        $this->normalizeBooleanQuery($request, 'favorites');
        $filters = $request->validate([
            'groupId' => ['nullable', 'integer', 'min:1'],
            'q' => ['nullable', 'string', 'max:100'],
            'favorites' => ['nullable', 'boolean'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
            'cursor' => ['nullable', 'string', 'max:512'],
        ]);
        $now = CarbonImmutable::now();
        $escaped = isset($filters['q'])
            ? addcslashes(trim($filters['q']), '\\%_')
            : null;
        $channels = $this->availableChannels($request)
            ->when(
                isset($filters['groupId']),
                fn (Builder $query) => $query->where(
                    'channels.channel_group_id',
                    $filters['groupId'],
                ),
            )
            ->when(
                $escaped !== null && $escaped !== '',
                fn (Builder $query) => $query->whereRaw(
                    "channels.name LIKE ? ESCAPE '\\'",
                    ['%'.$escaped.'%'],
                ),
            )
            ->when(
                ($filters['favorites'] ?? false) === true,
                fn (Builder $query) => $query->whereHas(
                    'favorites',
                    fn (Builder $favorite) => $favorite->where(
                        'user_id',
                        $request->user()->id,
                    ),
                ),
            )
            ->with([
                'programs' => fn ($query) => $query
                    ->where('ends_at', '>', $now)
                    ->where('starts_at', '<', $now->addHours(2))
                    ->orderBy('starts_at')
                    ->limit(3),
            ])
            ->orderBy('channels.name')
            ->orderBy('channels.id')
            ->cursorPaginate($filters['limit'] ?? 50);

        return $this->paginated($channels);
    }

    public function channel(
        Request $request,
        string $channel,
    ): JsonResponse {
        $item = $this->availableChannels($request)
            ->with([
                'programs' => fn ($query) => $query
                    ->where('ends_at', '>', now())
                    ->where('starts_at', '<', now()->addHours(8))
                    ->orderBy('starts_at')
                    ->limit(16),
            ])
            ->findOrFail($channel);

        return response()->json([
            'data' => new ChannelResource($item),
        ], headers: ['Cache-Control' => 'private, max-age=30']);
    }

    public function guide(Request $request): JsonResponse
    {
        $this->normalizeBooleanQuery($request, 'favorites');
        $validated = $request->validate([
            'startsAt' => ['nullable', 'date'],
            'endsAt' => ['nullable', 'date', 'after:startsAt'],
            'groupId' => ['nullable', 'integer', 'min:1'],
            'favorites' => ['nullable', 'boolean'],
            'timezone' => ['nullable', 'timezone'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
            'cursor' => ['nullable', 'string', 'max:512'],
        ]);
        $start = isset($validated['startsAt'])
            ? CarbonImmutable::parse($validated['startsAt'])->utc()
            : CarbonImmutable::now()->startOfHour();
        $end = isset($validated['endsAt'])
            ? CarbonImmutable::parse($validated['endsAt'])->utc()
            : $start->addHours(6);
        abort_if(
            $end->lessThanOrEqualTo($start)
            || $end->greaterThan($start->addHours(24)),
            422,
            'The guide window must be between one minute and 24 hours.',
        );

        $channels = $this->availableChannels($request)
            ->when(
                isset($validated['groupId']),
                fn (Builder $query) => $query->where(
                    'channels.channel_group_id',
                    $validated['groupId'],
                ),
            )
            ->when(
                ($validated['favorites'] ?? false) === true,
                fn (Builder $query) => $query->whereHas(
                    'favorites',
                    fn (Builder $favorite) => $favorite->where(
                        'user_id',
                        $request->user()->id,
                    ),
                ),
            )
            ->with([
                'programs' => fn ($query) => $query
                    ->where('ends_at', '>', $start)
                    ->where('starts_at', '<', $end)
                    ->orderBy('starts_at')
                    // A 100-channel page × 250 programs would hydrate and
                    // encode ~25k rows per request on the largest table in
                    // the system; the grid only renders a few hours, so a
                    // bounded slice keeps payloads predictable.
                    ->limit(32),
            ])
            ->orderBy('channels.name')
            ->orderBy('channels.id')
            ->cursorPaginate($validated['limit'] ?? 50);

        return response()->json([
            'window' => [
                'startsAt' => $start->toIso8601String(),
                'endsAt' => $end->toIso8601String(),
                'timezone' => $validated['timezone']
                    ?? $request->user()->timezone
                    ?? 'UTC',
            ],
            'channels' => ChannelResource::collection(
                $channels->getCollection(),
            ),
            'page' => $this->page($channels),
        ], headers: ['Cache-Control' => 'private, max-age=30']);
    }

    public function schedule(
        Request $request,
        string $channel,
    ): JsonResponse {
        $validated = $request->validate([
            'startsAt' => ['nullable', 'date'],
            'endsAt' => ['nullable', 'date', 'after:startsAt'],
        ]);
        $item = $this->availableChannels($request)->findOrFail($channel);
        $start = isset($validated['startsAt'])
            ? CarbonImmutable::parse($validated['startsAt'])->utc()
            : CarbonImmutable::now();
        $end = isset($validated['endsAt'])
            ? CarbonImmutable::parse($validated['endsAt'])->utc()
            : $start->addHours(24);
        abort_if($end->greaterThan($start->addHours(48)), 422);
        $programs = EpgProgram::query()
            ->whereBelongsTo($item, 'channel')
            ->where('ends_at', '>', $start)
            ->where('starts_at', '<', $end)
            ->orderBy('starts_at')
            ->limit(500)
            ->get();

        return response()->json([
            'data' => EpgProgramResource::collection($programs),
        ], headers: ['Cache-Control' => 'private, max-age=30']);
    }

    public function favorites(Request $request): JsonResponse
    {
        $channels = $this->availableChannels($request)
            ->whereHas(
                'favorites',
                fn (Builder $favorite) => $favorite->where(
                    'user_id',
                    $request->user()->id,
                ),
            )
            ->with([
                'programs' => fn ($query) => $query
                    ->where('ends_at', '>', now())
                    ->where('starts_at', '<', now()->addHours(2))
                    ->orderBy('starts_at')
                    ->limit(3),
            ])
            ->orderBy('channels.name')
            ->limit(500)
            ->get();

        return response()->json([
            'data' => ChannelResource::collection($channels),
        ], headers: ['Cache-Control' => 'private, max-age=30']);
    }

    public function storeFavorite(
        Request $request,
        string $channel,
    ): JsonResponse {
        $item = $this->availableChannels($request)->findOrFail($channel);
        ChannelFavorite::query()->firstOrCreate([
            'user_id' => $request->user()->id,
            'channel_id' => $item->id,
        ]);

        return response()->json(['favorite' => true], headers: [
            'Cache-Control' => 'no-store',
        ]);
    }

    public function destroyFavorite(
        Request $request,
        string $channel,
    ): JsonResponse {
        $this->availableChannels($request)->findOrFail($channel);
        ChannelFavorite::query()->where([
            'user_id' => $request->user()->id,
            'channel_id' => $channel,
        ])->delete();

        return response()->json(['favorite' => false], headers: [
            'Cache-Control' => 'no-store',
        ]);
    }

    public function refreshGuide(
        Request $request,
        AdminAuditService $audit,
    ): JsonResponse {
        abort_unless($request->user()->isAdmin(), 403);
        $validated = $request->validate([
            'providerId' => ['nullable', 'integer', 'exists:iptv_providers,id'],
        ]);
        $providers = IptvProvider::query()
            ->where('enabled', true)
            ->when(
                isset($validated['providerId']),
                fn (Builder $query) => $query->whereKey(
                    $validated['providerId'],
                ),
            )
            ->pluck('id');
        foreach ($providers as $providerId) {
            SyncIptvGuide::dispatch((int) $providerId);
        }
        $auditId = $audit->record($request, 'iptv-guide.refresh');

        return response()->json([
            'queued' => $providers->count(),
            'auditId' => $auditId,
        ], 202, ['Cache-Control' => 'no-store']);
    }

    private function availableChannels(Request $request): Builder
    {
        return Channel::query()
            ->select('channels.*')
            ->join(
                'iptv_providers',
                'iptv_providers.id',
                '=',
                'channels.iptv_provider_id',
            )
            ->where('channels.is_active', true)
            ->where('iptv_providers.enabled', true)
            ->where(function (Builder $query): void {
                $query->whereNull('channels.channel_group_id')
                    ->orWhereHas(
                        'group',
                        fn (Builder $group) => $group->where(
                            'is_active',
                            true,
                        ),
                    );
            })
            ->with([
                'group:id,name',
                'favorites' => fn ($query) => $query->where(
                    'user_id',
                    $request->user()->id,
                ),
            ]);
    }

    private function paginated(CursorPaginator $channels): JsonResponse
    {
        return response()->json([
            'data' => ChannelResource::collection($channels->getCollection()),
            'page' => $this->page($channels),
        ], headers: ['Cache-Control' => 'private, max-age=30']);
    }

    /**
     * @return array<string, mixed>
     */
    private function page(CursorPaginator $paginator): array
    {
        return [
            'perPage' => $paginator->perPage(),
            'nextCursor' => $paginator->nextCursor()?->encode(),
            'previousCursor' => $paginator->previousCursor()?->encode(),
        ];
    }

    private function normalizeBooleanQuery(
        Request $request,
        string $key,
    ): void {
        $value = $request->query($key);
        if (! is_string($value)) {
            return;
        }

        $normalized = match (strtolower($value)) {
            '1', 'true' => true,
            '0', 'false' => false,
            default => null,
        };
        if ($normalized !== null) {
            $request->merge([$key => $normalized]);
        }
    }
}
