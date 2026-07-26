<?php

namespace App\Http\Controllers\Iptv\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Iptv\StoreIptvProviderRequest;
use App\Http\Requests\Iptv\UpdateIptvProviderRequest;
use App\Jobs\Iptv\SyncIptvGuide;
use App\Jobs\Iptv\SyncIptvProvider;
use App\Models\Iptv\IptvPlaybackSession;
use App\Models\Iptv\IptvProvider;
use App\Services\Iptv\Exceptions\SanitizedIptvException;
use App\Services\Iptv\UpstreamUrlGuard;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class IptvProviderController extends Controller
{
    public function index(): View
    {
        return view('iptv.admin.providers.index', [
            'providers' => IptvProvider::query()
                ->withCount(['groups', 'channels'])
                ->orderBy('name')
                ->get(),
        ]);
    }

    public function create(): View
    {
        return view('iptv.admin.providers.create');
    }

    public function store(
        StoreIptvProviderRequest $request,
        UpstreamUrlGuard $urlGuard,
    ): RedirectResponse {
        $validated = $request->validated();

        $providerType = $validated['provider_type'];
        $connectionUrl = $providerType === 'm3u' ? $validated['playlist_url'] : $validated['base_url'];
        try {
            if ($providerType === 'm3u') {
                $urlGuard->assertPublicTarget($connectionUrl, $validated['allow_insecure_http']);
                $baseUrl = preg_replace('#^(.+?//[^/]+).*$#', '$1', $connectionUrl);
            } else {
                $baseUrl = $urlGuard->normalizeBaseUrl(
                    $connectionUrl,
                    $validated['allow_insecure_http'],
                );
            }
        } catch (SanitizedIptvException) {
            throw ValidationException::withMessages([
                'base_url' => 'The provider address cannot be used.',
            ]);
        }

        $provider = IptvProvider::query()->create([
            'name' => $validated['name'],
            'base_url' => $baseUrl,
            'username' => $validated['username'] ?? '',
            'password' => $validated['password'] ?? '',
            'config' => [
                'api' => $providerType,
                'stream_format' => 'hls',
                'playlist_url' => $providerType === 'm3u' ? $validated['playlist_url'] : null,
                'xmltv_url' => $validated['xmltv_url'] ?? null,
                'max_connections' => $validated['max_connections'] ?? (int) config('iptv.provider_max_connections'),
                'max_connections_source' => isset($validated['max_connections'])
                    ? 'manual'
                    : 'default',
            ],
            'allow_insecure_http' => $validated['allow_insecure_http'],
            'enabled' => $validated['enabled'],
        ]);

        SyncIptvProvider::dispatch($provider->id);

        return redirect()
            ->route('iptv.admin.providers.index')
            ->with('status', 'Provider saved. Its catalog sync has been queued.');
    }

    public function edit(IptvProvider $provider): View
    {
        return view('iptv.admin.providers.edit', compact('provider'));
    }

    public function update(
        UpdateIptvProviderRequest $request,
        IptvProvider $provider,
        UpstreamUrlGuard $urlGuard,
    ): RedirectResponse {
        $validated = $request->validated();
        $invalidatePlayback = (
            $provider->enabled
            && ! $validated['enabled']
        );
        $changes = [
            'name' => $validated['name'],
            'allow_insecure_http' => $validated['allow_insecure_http'],
            'enabled' => $validated['enabled'],
        ];
        $config = $provider->config;
        $config['api'] = $validated['provider_type'];
        if (! empty($validated['playlist_url'])) {
            try {
                $urlGuard->assertPublicTarget($validated['playlist_url'], $validated['allow_insecure_http']);
            } catch (SanitizedIptvException) {
                throw ValidationException::withMessages(['playlist_url' => 'The playlist address cannot be used.']);
            }
            $config['playlist_url'] = $validated['playlist_url'];
            $invalidatePlayback = true;
        }
        if (array_key_exists('xmltv_url', $validated)) {
            $config['xmltv_url'] = $validated['xmltv_url'];
        }
        if (! empty($validated['max_connections'])) {
            $config['max_connections'] = (int) $validated['max_connections'];
            $config['max_connections_source'] = 'manual';
        }
        $changes['config'] = $config;

        if (! empty($validated['base_url'])) {
            $invalidatePlayback = true;

            try {
                $changes['base_url'] = $urlGuard->normalizeBaseUrl(
                    $validated['base_url'],
                    $validated['allow_insecure_http'],
                );
            } catch (SanitizedIptvException) {
                throw ValidationException::withMessages([
                    'base_url' => 'The provider address cannot be used.',
                ]);
            }
        }

        foreach (['username', 'password'] as $secret) {
            if (! empty($validated[$secret])) {
                $changes[$secret] = $validated[$secret];
                $invalidatePlayback = true;
            }
        }

        $provider->update($changes);
        $provider->vodSource()->update([
            'enabled' => $provider->enabled,
            'name' => Str::limit(
                $provider->name.' · IPTV #'.$provider->id,
                255,
                '',
            ),
        ]);

        if ($invalidatePlayback) {
            IptvPlaybackSession::query()
                ->whereHas(
                    'channel',
                    fn ($query) => $query->where('iptv_provider_id', $provider->id),
                )
                ->update([
                    'status' => 'invalidated',
                    'last_error_code' => 'provider_connection_changed',
                    'expires_at' => now(),
                    'updated_at' => now(),
                ]);
        }

        return redirect()
            ->route('iptv.admin.providers.index')
            ->with('status', 'Provider settings updated.');
    }

    public function destroy(IptvProvider $provider): RedirectResponse
    {
        $provider->delete();

        return redirect()
            ->route('iptv.admin.providers.index')
            ->with('status', 'Provider and its imported catalog were removed.');
    }

    public function sync(IptvProvider $provider): RedirectResponse
    {
        SyncIptvProvider::dispatch($provider->id);

        return back()->with('status', 'Catalog sync queued.');
    }

    public function syncGuide(IptvProvider $provider): RedirectResponse
    {
        SyncIptvGuide::dispatch($provider->id);

        return back()->with('status', 'Current and next guide sync queued.');
    }
}
