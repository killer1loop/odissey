@csrf
@if ($provider ?? false)
    @method('PUT')
@endif

<label class="iptv-label">
    Provider type
    <select class="iptv-input" name="provider_type"><option value="xtream" @selected(($provider?->config['api'] ?? old('provider_type','xtream')) === 'xtream')>Xtream-compatible</option><option value="m3u" @selected(($provider?->config['api'] ?? old('provider_type')) === 'm3u')>Generic M3U + XMLTV</option></select>
</label>
<label class="iptv-label">
    Provider name
    <input class="iptv-input" name="name" required maxlength="255" value="{{ old('name', $provider?->name) }}">
    @error('name') <span class="iptv-error">{{ $message }}</span> @enderror
</label>
<label class="iptv-label">M3U playlist URL<input class="iptv-input" name="playlist_url" type="url" autocomplete="off" value=""></label>
<label class="iptv-label">XMLTV guide URL (optional)<input class="iptv-input" name="xmltv_url" type="url" autocomplete="off" value=""></label>
<label class="iptv-label">Maximum simultaneous streams<input class="iptv-input" name="max_connections" type="number" min="1" max="100" value="{{ old('max_connections',$provider?->config['max_connections'] ?? 1) }}"></label>

<label class="iptv-label">
    {{ $provider ? 'New provider base address (leave blank to keep stored value)' : 'Provider base address' }}
    <input class="iptv-input" name="base_url" type="url" @required(! $provider) autocomplete="off" value="">
    @error('base_url') <span class="iptv-error">{{ $message }}</span> @enderror
</label>

<label class="iptv-label">
    {{ $provider ? 'New username (leave blank to keep stored value)' : 'Username' }}
    <input class="iptv-input" name="username" @required(! $provider) autocomplete="off" value="">
    @error('username') <span class="iptv-error">{{ $message }}</span> @enderror
</label>

<label class="iptv-label">
    {{ $provider ? 'New password (leave blank to keep stored value)' : 'Password' }}
    <input class="iptv-input" name="password" type="password" @required(! $provider) autocomplete="new-password" value="">
    @error('password') <span class="iptv-error">{{ $message }}</span> @enderror
</label>

<label class="iptv-check">
    <input name="allow_insecure_http" type="checkbox" value="1" @checked(old('allow_insecure_http', $provider?->allow_insecure_http ?? false))>
    <span>I explicitly consent to sending these credentials over unencrypted HTTP. This is required only when the provider does not support HTTPS.</span>
</label>
@error('allow_insecure_http') <span class="iptv-error">{{ $message }}</span> @enderror

<label class="iptv-check">
    <input name="enabled" type="checkbox" value="1" @checked(old('enabled', $provider?->enabled ?? true))>
    <span>Enable this provider for catalog sync and playback.</span>
</label>

<div class="iptv-card-actions">
    <button class="button button-primary" type="submit">{{ $provider ? 'Save changes' : 'Save provider' }}</button>
    <a class="button button-muted" href="{{ route('iptv.admin.providers.index') }}">Cancel</a>
</div>
