<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\ApiProblem;
use App\Http\Controllers\Controller;
use App\Jobs\Iptv\SyncIptvGuide;
use App\Jobs\Iptv\SyncIptvProvider;
use App\Jobs\Media\EnrichMediaItem;
use App\Jobs\Media\FetchMediaCaptions;
use App\Models\IntegrationSetting;
use App\Models\Iptv\IptvPlaybackSession;
use App\Models\Iptv\IptvProvider;
use App\Models\MediaItem;
use App\Models\MediaSource;
use App\Models\User;
use App\Services\Api\AdminAuditService;
use App\Services\Auth\SessionRevoker;
use App\Services\IntegrationSettings;
use App\Services\Iptv\Exceptions\SanitizedIptvException;
use App\Services\Iptv\M3uClient;
use App\Services\Iptv\UpstreamUrlGuard;
use App\Services\Iptv\XtreamClient;
use App\Services\Media\MediaScanDispatcher;
use App\Services\Media\Sources\MediaSourceRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Throwable;

class AdminManagementController extends Controller
{
    public function __construct(
        private readonly AdminAuditService $audit,
    ) {}

    public function createUser(Request $request): JsonResponse
    {
        $this->authorizeAdmin($request);
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => [
                'required',
                'string',
                'email',
                'max:255',
                'unique:users,email',
            ],
            'password' => [
                'required',
                'string',
                'max:255',
                Password::min(12)->letters()->mixedCase()->numbers()->symbols(),
            ],
            'passwordConfirmation' => ['required', 'same:password'],
            'isAdmin' => ['nullable', 'boolean'],
        ]);
        $user = new User([
            'name' => $validated['name'],
            'email' => Str::lower(trim($validated['email'])),
            'password' => $validated['password'],
        ]);
        $user->is_admin = (bool) ($validated['isAdmin'] ?? false);
        $user->is_active = true;
        $user->email_verified_at = now();
        $user->save();
        $auditId = $this->audit->record($request, 'user.create', $user);

        return response()->json([
            'data' => $this->userPayload($user),
            'auditId' => $auditId,
        ], 201, ['Cache-Control' => 'no-store']);
    }

    public function updateUser(
        Request $request,
        User $user,
        SessionRevoker $sessions,
    ): JsonResponse {
        $this->authorizeAdmin($request);
        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'email' => [
                'sometimes',
                'string',
                'email',
                'max:255',
                Rule::unique('users', 'email')->ignore($user),
            ],
            'isAdmin' => ['sometimes', 'boolean'],
        ]);
        if (
            array_key_exists('isAdmin', $validated)
            && ! $validated['isAdmin']
            && $user->is_admin
        ) {
            abort_if($user->is($request->user()), 409);
            abort_if(
                User::query()
                    ->where('is_admin', true)
                    ->where('is_active', true)
                    ->count() <= 1,
                409,
                'The last active administrator cannot be demoted.',
            );
        }
        $changes = [];
        if (isset($validated['name'])) {
            $changes['name'] = $validated['name'];
        }
        if (isset($validated['email'])) {
            $changes['email'] = Str::lower(trim($validated['email']));
        }
        if (array_key_exists('isAdmin', $validated)) {
            $changes['is_admin'] = $validated['isAdmin'];
        }
        if ($changes !== []) {
            $user->update($changes);
            if (isset($changes['email']) || isset($changes['is_admin'])) {
                $sessions->revokeAllSessions($user);
            }
        }
        $auditId = $this->audit->record($request, 'user.update', $user);

        return response()->json([
            'data' => $this->userPayload($user->refresh()),
            'auditId' => $auditId,
        ], headers: ['Cache-Control' => 'no-store']);
    }

    public function disableUser(
        Request $request,
        User $user,
        SessionRevoker $sessions,
    ): JsonResponse {
        $this->authorizeAdmin($request);
        $request->validate([
            'confirmation' => ['required', 'in:disable-user'],
        ]);
        abort_if($user->is_admin || $user->is($request->user()), 403);
        $user->forceFill([
            'is_active' => false,
            'disabled_at' => now(),
            'remember_token' => null,
        ])->save();
        $sessions->revokeAllSessions($user);
        $auditId = $this->audit->record($request, 'user.disable', $user);

        return response()->json([
            'data' => $this->userPayload($user),
            'auditId' => $auditId,
        ], headers: ['Cache-Control' => 'no-store']);
    }

    public function createProvider(
        Request $request,
        UpstreamUrlGuard $guard,
    ): JsonResponse {
        $this->authorizeAdmin($request);
        $data = $this->validateProvider($request, null);
        [$baseUrl, $config] = $this->providerConnection($data, $guard);
        $provider = IptvProvider::query()->create([
            'name' => $data['name'],
            'base_url' => $baseUrl,
            'username' => $data['username'] ?? '',
            'password' => $data['password'] ?? '',
            'config' => $config,
            'allow_insecure_http' => $data['allowInsecureHttp'] ?? false,
            'enabled' => $data['enabled'] ?? true,
        ]);
        SyncIptvProvider::dispatch($provider->id);
        $auditId = $this->audit->record(
            $request,
            'iptv-provider.create',
            $provider,
        );

        return response()->json([
            'data' => $this->providerPayload($provider),
            'catalogSyncQueued' => true,
            'auditId' => $auditId,
        ], 201, ['Cache-Control' => 'no-store']);
    }

    public function updateProvider(
        Request $request,
        IptvProvider $provider,
        UpstreamUrlGuard $guard,
    ): JsonResponse {
        $this->authorizeAdmin($request);
        $data = $this->validateProvider($request, $provider);
        $connectionChanged = false;
        $changes = [
            'name' => $data['name'],
            'allow_insecure_http' => $data['allowInsecureHttp']
                ?? $provider->allow_insecure_http,
            'enabled' => $data['enabled'] ?? $provider->enabled,
        ];
        $config = $provider->config;
        $config['api'] = $data['providerType'];
        foreach (['playlistUrl' => 'playlist_url', 'xmltvUrl' => 'xmltv_url'] as $input => $key) {
            if (array_key_exists($input, $data)) {
                $config[$key] = $data[$input];
                $connectionChanged = true;
            }
        }
        if (isset($data['maxConnections'])) {
            $config['max_connections'] = $data['maxConnections'];
            $config['max_connections_source'] = 'manual';
        }
        $changes['config'] = $config;
        if (isset($data['baseUrl'])) {
            try {
                $changes['base_url'] = $guard->normalizeBaseUrl(
                    $data['baseUrl'],
                    (bool) $changes['allow_insecure_http'],
                );
            } catch (SanitizedIptvException) {
                throw ValidationException::withMessages([
                    'baseUrl' => 'The provider address cannot be used.',
                ]);
            }
            $connectionChanged = true;
        }
        foreach (['username', 'password'] as $secret) {
            if (isset($data[$secret]) && $data[$secret] !== '') {
                $changes[$secret] = $data[$secret];
                $connectionChanged = true;
            }
        }
        if ($provider->enabled && ! $changes['enabled']) {
            $connectionChanged = true;
        }
        $provider->update($changes);
        $provider->vodSource?->update(['enabled' => $provider->enabled]);
        if ($connectionChanged) {
            IptvPlaybackSession::query()
                ->whereHas(
                    'channel',
                    fn ($query) => $query->where(
                        'iptv_provider_id',
                        $provider->id,
                    ),
                )
                ->update([
                    'status' => 'invalidated',
                    'last_error_code' => 'provider_connection_changed',
                    'expires_at' => now(),
                    'updated_at' => now(),
                ]);
        }
        $auditId = $this->audit->record(
            $request,
            'iptv-provider.update',
            $provider,
        );

        return response()->json([
            'data' => $this->providerPayload($provider->refresh()),
            'auditId' => $auditId,
        ], headers: ['Cache-Control' => 'no-store']);
    }

    public function testProvider(
        Request $request,
        IptvProvider $provider,
        XtreamClient $xtream,
        M3uClient $m3u,
    ): JsonResponse {
        $this->authorizeAdmin($request);
        try {
            if (($provider->config['api'] ?? 'xtream') === 'm3u') {
                [$groups, $channels] = $m3u->catalog($provider);
                $result = [
                    'valid' => true,
                    'groupCount' => count($groups),
                    'channelCount' => count($channels),
                ];
            } else {
                $result = [
                    'valid' => true,
                    'maximumConnections' => $xtream->authenticate($provider),
                ];
            }
        } catch (SanitizedIptvException $exception) {
            return ApiProblem::response(
                422,
                'provider_validation_failed',
                'Provider validation failed',
                'The provider could not be authenticated or validated.',
                ['errorCode' => $exception->errorCode],
            );
        }

        return response()->json($result, headers: [
            'Cache-Control' => 'no-store',
        ]);
    }

    public function syncProvider(
        Request $request,
        IptvProvider $provider,
    ): JsonResponse {
        $this->authorizeAdmin($request);
        SyncIptvProvider::dispatch($provider->id);
        $auditId = $this->audit->record(
            $request,
            'iptv-provider.sync',
            $provider,
        );

        return response()->json([
            'queued' => true,
            'auditId' => $auditId,
        ], 202, [
            'Cache-Control' => 'no-store',
        ]);
    }

    public function syncProviderGuide(
        Request $request,
        IptvProvider $provider,
    ): JsonResponse {
        $this->authorizeAdmin($request);
        SyncIptvGuide::dispatch($provider->id);
        $auditId = $this->audit->record(
            $request,
            'iptv-provider.epg-sync',
            $provider,
        );

        return response()->json([
            'queued' => true,
            'auditId' => $auditId,
        ], 202, [
            'Cache-Control' => 'no-store',
        ]);
    }

    public function deleteProvider(
        Request $request,
        IptvProvider $provider,
    ): JsonResponse {
        $this->authorizeAdmin($request);
        $request->validate([
            'confirmation' => ['required', 'in:delete-provider'],
        ]);
        $provider->delete();
        $auditId = $this->audit->record(
            $request,
            'iptv-provider.delete',
            $provider,
        );

        return response()->json([
            'deleted' => true,
            'auditId' => $auditId,
        ], headers: [
            'Cache-Control' => 'no-store',
        ]);
    }

    public function createMediaSource(
        Request $request,
        MediaSourceRegistry $registry,
        MediaScanDispatcher $dispatcher,
    ): JsonResponse {
        $this->authorizeAdmin($request);
        $data = $this->validateMediaSource($request, null);
        $source = MediaSource::query()->create([
            'name' => $data['name'],
            'type' => $data['type'],
            'configuration' => $this->sourceConfiguration($data),
            'enabled' => $data['enabled'] ?? true,
            'allow_private_network' => $data['allowPrivateNetwork'] ?? false,
        ]);
        try {
            $source->update([
                'capabilities' => $registry->for($source)
                    ->capabilities($source),
            ]);
        } catch (Throwable) {
            $source->delete();

            throw ValidationException::withMessages([
                'name' => 'The read-only source could not be reached or validated.',
            ]);
        }
        $queued = $dispatcher->queue($source);
        $auditId = $this->audit->record(
            $request,
            'media-source.create',
            $source,
        );

        return response()->json([
            'data' => $this->sourcePayload($source->refresh()),
            'scanQueued' => $queued,
            'auditId' => $auditId,
        ], 201, ['Cache-Control' => 'no-store']);
    }

    public function updateMediaSource(
        Request $request,
        MediaSource $source,
        MediaSourceRegistry $registry,
    ): JsonResponse {
        $this->authorizeAdmin($request);
        abort_if($source->type === MediaSource::TYPE_IPTV, 404);
        $data = $this->validateMediaSource($request, $source);
        $configuration = $this->sourceConfiguration(
            $data,
            $source->configuration,
        );
        $candidate = new MediaSource([
            'name' => $data['name'],
            'type' => $source->type,
            'configuration' => $configuration,
            'enabled' => $data['enabled'] ?? $source->enabled,
            'allow_private_network' => $data['allowPrivateNetwork']
                ?? $source->allow_private_network,
        ]);
        try {
            $capabilities = $registry->for($candidate)
                ->capabilities($candidate);
        } catch (Throwable) {
            throw ValidationException::withMessages([
                'name' => 'The read-only source could not be reached or validated.',
            ]);
        }
        $source->update([
            'name' => $candidate->name,
            'configuration' => $configuration,
            'enabled' => $candidate->enabled,
            'allow_private_network' => $candidate->allow_private_network,
            'capabilities' => $capabilities,
        ]);
        $auditId = $this->audit->record(
            $request,
            'media-source.update',
            $source,
        );

        return response()->json([
            'data' => $this->sourcePayload($source->refresh()),
            'auditId' => $auditId,
        ], headers: ['Cache-Control' => 'no-store']);
    }

    public function testMediaSource(
        Request $request,
        MediaSource $source,
        MediaSourceRegistry $registry,
    ): JsonResponse {
        $this->authorizeAdmin($request);
        abort_if($source->type === MediaSource::TYPE_IPTV, 404);
        try {
            $capabilities = $registry->for($source)
                ->capabilities($source);
        } catch (Throwable) {
            return ApiProblem::response(
                422,
                'source_validation_failed',
                'Source validation failed',
                'The read-only source could not be reached or validated.',
            );
        }

        return response()->json([
            'valid' => true,
            'capabilities' => $capabilities,
        ], headers: ['Cache-Control' => 'no-store']);
    }

    public function scanMediaSource(
        Request $request,
        MediaSource $source,
        MediaScanDispatcher $dispatcher,
    ): JsonResponse {
        $this->authorizeAdmin($request);
        abort_if($source->type === MediaSource::TYPE_IPTV, 404);
        $queued = $dispatcher->queue($source);
        $auditId = $this->audit->record(
            $request,
            'media-source.scan',
            $source,
        );

        return response()->json([
            'queued' => $queued,
            'auditId' => $auditId,
        ], 202, ['Cache-Control' => 'no-store']);
    }

    public function deleteMediaSource(
        Request $request,
        MediaSource $source,
    ): JsonResponse {
        $this->authorizeAdmin($request);
        abort_if($source->type === MediaSource::TYPE_IPTV, 404);
        $request->validate([
            'confirmation' => ['required', 'in:delete-media-source'],
        ]);
        $source->items()->eachById(fn (MediaItem $item) => $item->delete());
        $source->delete();
        $auditId = $this->audit->record(
            $request,
            'media-source.delete',
            $source,
        );

        return response()->json([
            'deleted' => true,
            'auditId' => $auditId,
        ], headers: [
            'Cache-Control' => 'no-store',
        ]);
    }

    public function integrations(
        Request $request,
        IntegrationSettings $settings,
    ): JsonResponse {
        $this->authorizeAdmin($request);

        return response()->json([
            'tmdbConfigured' => $settings->has(
                'tmdb_api_token',
                config('services.tmdb.token'),
            ),
            'subdlConfigured' => $settings->has(
                'subdl_api_key',
                config('services.subdl.api_key'),
            ),
            'openSubtitlesConfigured' => $settings->has(
                'opensubtitles_api_key',
                config('services.opensubtitles.api_key'),
            ),
            'captionLanguages' => explode(',', (string) $settings->get(
                'caption_languages',
                implode(',', config('odissey.caption_languages')),
            )),
        ], headers: ['Cache-Control' => 'no-store']);
    }

    public function updateIntegrations(Request $request): JsonResponse
    {
        $this->authorizeAdmin($request);
        $data = $request->validate([
            'tmdbApiToken' => ['nullable', 'string', 'max:4096'],
            'subdlApiKey' => ['nullable', 'string', 'max:4096'],
            'openSubtitlesApiKey' => ['nullable', 'string', 'max:4096'],
            'captionLanguages' => [
                'required',
                'array',
                'min:1',
                'max:20',
            ],
            'captionLanguages.*' => [
                'string',
                'regex:/^[a-zA-Z]{2,3}$/',
            ],
            'clear' => ['nullable', 'array', 'max:3'],
            'clear.*' => [
                'in:tmdbApiToken,subdlApiKey,openSubtitlesApiKey',
            ],
        ]);
        $mapping = [
            'tmdbApiToken' => 'tmdb_api_token',
            'subdlApiKey' => 'subdl_api_key',
            'openSubtitlesApiKey' => 'opensubtitles_api_key',
        ];
        foreach ($mapping as $input => $key) {
            if (in_array($input, $data['clear'] ?? [], true)) {
                IntegrationSetting::query()->whereKey($key)->delete();
            } elseif (isset($data[$input]) && $data[$input] !== '') {
                IntegrationSetting::query()->updateOrCreate(
                    ['key' => $key],
                    ['value' => $data[$input]],
                );
            }
        }
        $languages = implode(',', array_values(array_unique(array_map(
            static fn (string $language): string => strtolower($language),
            $data['captionLanguages'],
        ))));
        IntegrationSetting::query()->updateOrCreate(
            ['key' => 'caption_languages'],
            ['value' => $languages],
        );
        $auditId = $this->audit->record(
            $request,
            'integrations.update',
        );

        return response()->json([
            'updated' => true,
            'captionLanguages' => explode(',', $languages),
            'auditId' => $auditId,
        ], headers: ['Cache-Control' => 'no-store']);
    }

    public function refreshMetadata(
        Request $request,
        MediaItem $media,
    ): JsonResponse {
        $this->authorizeAdmin($request);
        EnrichMediaItem::dispatch($media->id);
        $auditId = $this->audit->record(
            $request,
            'media.metadata-refresh',
            $media,
        );

        return response()->json([
            'queued' => true,
            'auditId' => $auditId,
        ], 202, [
            'Cache-Control' => 'no-store',
        ]);
    }

    public function refreshCaptions(
        Request $request,
        MediaItem $media,
    ): JsonResponse {
        $this->authorizeAdmin($request);
        FetchMediaCaptions::dispatch($media->id);
        $auditId = $this->audit->record(
            $request,
            'media.captions-refresh',
            $media,
        );

        return response()->json([
            'queued' => true,
            'auditId' => $auditId,
        ], 202, [
            'Cache-Control' => 'no-store',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function validateProvider(
        Request $request,
        ?IptvProvider $provider,
    ): array {
        $providerType = $request->input(
            'providerType',
            $provider?->config['api'] ?? 'xtream',
        );
        $request->merge(['providerType' => $providerType]);

        return $request->validate([
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('iptv_providers', 'name')->ignore($provider),
            ],
            'providerType' => ['required', 'in:xtream,m3u'],
            'baseUrl' => [
                $provider === null && $providerType === 'xtream'
                    ? 'required'
                    : 'nullable',
                'string',
                'max:2048',
                'url:http,https',
            ],
            'playlistUrl' => [
                $provider === null && $providerType === 'm3u'
                    ? 'required'
                    : 'nullable',
                'string',
                'max:4096',
                'url:http,https',
            ],
            'xmltvUrl' => ['nullable', 'string', 'max:4096', 'url:http,https'],
            'maxConnections' => ['nullable', 'integer', 'min:1', 'max:100'],
            'username' => [
                $provider === null && $providerType === 'xtream'
                    ? 'required'
                    : 'nullable',
                'string',
                'max:1024',
            ],
            'password' => [
                $provider === null && $providerType === 'xtream'
                    ? 'required'
                    : 'nullable',
                'string',
                'max:1024',
            ],
            'allowInsecureHttp' => ['nullable', 'boolean'],
            'enabled' => ['nullable', 'boolean'],
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function providerConnection(
        array $data,
        UpstreamUrlGuard $guard,
    ): array {
        $insecure = (bool) ($data['allowInsecureHttp'] ?? false);
        try {
            if ($data['providerType'] === 'm3u') {
                $guard->assertPublicTarget($data['playlistUrl'], $insecure);
                $baseUrl = preg_replace(
                    '#^(.+?//[^/]+).*$#',
                    '$1',
                    $data['playlistUrl'],
                );
            } else {
                $baseUrl = $guard->normalizeBaseUrl(
                    $data['baseUrl'],
                    $insecure,
                );
            }
        } catch (SanitizedIptvException) {
            throw ValidationException::withMessages([
                'baseUrl' => 'The provider address cannot be used.',
            ]);
        }

        return [
            (string) $baseUrl,
            [
                'api' => $data['providerType'],
                'stream_format' => 'hls',
                'playlist_url' => $data['playlistUrl'] ?? null,
                'xmltv_url' => $data['xmltvUrl'] ?? null,
                'max_connections' => $data['maxConnections']
                    ?? (int) config('iptv.provider_max_connections'),
                'max_connections_source' => isset($data['maxConnections'])
                    ? 'manual'
                    : 'default',
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function validateMediaSource(
        Request $request,
        ?MediaSource $source,
    ): array {
        $type = $source?->type ?? $request->input('type');
        if ($source !== null) {
            $request->merge(['type' => $source->type]);
        }

        return $request->validate([
            'name' => [
                'required',
                'string',
                'max:100',
                Rule::unique('media_sources', 'name')->ignore($source),
            ],
            'type' => [
                'required',
                $source === null
                    ? Rule::in([
                        MediaSource::TYPE_LOCAL,
                        MediaSource::TYPE_S3,
                        MediaSource::TYPE_WEBDAV,
                    ])
                    : Rule::in([$source->type]),
            ],
            'path' => [
                Rule::requiredIf(
                    $source === null && $type === MediaSource::TYPE_LOCAL,
                ),
                'nullable',
                'string',
                'max:2048',
            ],
            'url' => [
                Rule::requiredIf(
                    $source === null && $type === MediaSource::TYPE_WEBDAV,
                ),
                'nullable',
                'url:http,https',
                'max:2048',
            ],
            'endpoint' => [
                Rule::requiredIf(
                    $source === null && $type === MediaSource::TYPE_S3,
                ),
                'nullable',
                'url:http,https',
                'max:2048',
            ],
            'bucket' => [
                Rule::requiredIf(
                    $source === null && $type === MediaSource::TYPE_S3,
                ),
                'nullable',
                'string',
                'max:255',
            ],
            'prefix' => ['nullable', 'string', 'max:1024'],
            'region' => ['nullable', 'string', 'max:64'],
            'accessKey' => ['nullable', 'string', 'max:512'],
            'secretKey' => ['nullable', 'string', 'max:512'],
            'username' => ['nullable', 'string', 'max:512'],
            'password' => ['nullable', 'string', 'max:512'],
            'allowPrivateNetwork' => ['nullable', 'boolean'],
            'enabled' => ['nullable', 'boolean'],
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $existing
     * @return array<string, mixed>
     */
    private function sourceConfiguration(
        array $data,
        array $existing = [],
    ): array {
        $type = $data['type'] ?? match (true) {
            array_key_exists('endpoint', $existing) => MediaSource::TYPE_S3,
            array_key_exists('url', $existing) => MediaSource::TYPE_WEBDAV,
            default => MediaSource::TYPE_LOCAL,
        };
        $mapping = match ($type) {
            MediaSource::TYPE_LOCAL => ['path' => 'path'],
            MediaSource::TYPE_S3 => [
                'endpoint' => 'endpoint',
                'bucket' => 'bucket',
                'prefix' => 'prefix',
                'region' => 'region',
                'accessKey' => 'access_key',
                'secretKey' => 'secret_key',
            ],
            default => [
                'url' => 'url',
                'username' => 'username',
                'password' => 'password',
            ],
        };
        $configuration = $existing;
        foreach ($mapping as $input => $stored) {
            if (array_key_exists($input, $data) && $data[$input] !== null) {
                if ($data[$input] !== '' || ! in_array(
                    $stored,
                    ['access_key', 'secret_key', 'username', 'password'],
                    true,
                )) {
                    $configuration[$stored] = $data[$input];
                }
            }
        }

        return $configuration;
    }

    /**
     * @return array<string, mixed>
     */
    private function userPayload(User $user): array
    {
        return [
            'id' => (string) $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'isAdmin' => $user->isAdmin(),
            'isActive' => $user->isActive(),
            'disabledAt' => $user->disabled_at?->utc()->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function providerPayload(IptvProvider $provider): array
    {
        return [
            'id' => (string) $provider->id,
            'name' => $provider->name,
            'providerType' => $provider->config['api'] ?? 'xtream',
            'enabled' => (bool) $provider->enabled,
            'allowInsecureHttp' => (bool) $provider->allow_insecure_http,
            'syncStatus' => $provider->sync_status,
            'lastErrorCode' => $provider->last_error_code,
            'usernameConfigured' => trim((string) $provider->username) !== '',
            'passwordConfigured' => trim((string) $provider->password) !== '',
            'playlistConfigured' => trim((string) (
                $provider->config['playlist_url'] ?? ''
            )) !== '',
            'xmltvConfigured' => trim((string) (
                $provider->config['xmltv_url'] ?? ''
            )) !== '',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function sourcePayload(MediaSource $source): array
    {
        $configuration = $source->configuration;

        return [
            'id' => (string) $source->id,
            'name' => $source->name,
            'type' => $source->type,
            'enabled' => (bool) $source->enabled,
            'allowPrivateNetwork' => (bool) $source->allow_private_network,
            'scanStatus' => $source->scan_status,
            'lastErrorCode' => $source->last_error_code,
            'capabilities' => $source->capabilities ?? [],
            'credentialsConfigured' => match ($source->type) {
                MediaSource::TYPE_S3 => (
                    trim((string) ($configuration['access_key'] ?? '')) !== ''
                    && trim((string) (
                        $configuration['secret_key'] ?? ''
                    )) !== ''
                ),
                MediaSource::TYPE_WEBDAV => (
                    trim((string) ($configuration['username'] ?? '')) !== ''
                    || trim((string) ($configuration['password'] ?? '')) !== ''
                ),
                default => false,
            },
        ];
    }

    private function authorizeAdmin(Request $request): void
    {
        abort_unless($request->user()->isAdmin(), 403);
    }
}
