@csrf
@if ($provider ?? false)
    @method('PUT')
@endif

<label class="field">
    Provider type
    <select name="provider_type"><option value="xtream" @selected(($provider?->config['api'] ?? old('provider_type','xtream')) === 'xtream')>Xtream-compatible</option><option value="m3u" @selected(($provider?->config['api'] ?? old('provider_type')) === 'm3u')>Generic M3U + XMLTV</option></select>
</label>
<label class="field">
    Provider name
    <input name="name" required maxlength="255" value="{{ old('name', $provider?->name) }}">
    @error('name') <span class="field-error">{{ $message }}</span> @enderror
</label>
<label class="field">M3U playlist URL<input name="playlist_url" type="url" autocomplete="off" value=""></label>
<label class="field">XMLTV guide URL <span class="field-hint">Optional</span><input name="xmltv_url" type="url" autocomplete="off" value=""></label>
<label class="field">Maximum simultaneous streams<input name="max_connections" type="number" min="1" max="100" value="{{ old('max_connections',$provider?->config['max_connections'] ?? 1) }}"></label>

<label class="field">
    {{ $provider ? 'New provider base address (leave blank to keep stored value)' : 'Provider base address' }}
    <input name="base_url" type="url" @required(! $provider) autocomplete="off" value="">
    @error('base_url') <span class="field-error">{{ $message }}</span> @enderror
</label>

<label class="field">
    {{ $provider ? 'New username (leave blank to keep stored value)' : 'Username' }}
    <input name="username" @required(! $provider) autocomplete="off" value="">
    @error('username') <span class="field-error">{{ $message }}</span> @enderror
</label>

<label class="field">
    {{ $provider ? 'New password (leave blank to keep stored value)' : 'Password' }}
    <input name="password" type="password" @required(! $provider) autocomplete="new-password" value="">
    @error('password') <span class="field-error">{{ $message }}</span> @enderror
</label>

<label class="checkbox-row">
    <input name="allow_insecure_http" type="checkbox" value="1" @checked(old('allow_insecure_http', $provider?->allow_insecure_http ?? false))>
    <span>I explicitly consent to sending these credentials over unencrypted HTTP. This is required only when the provider does not support HTTPS.</span>
</label>
@error('allow_insecure_http') <span class="field-error">{{ $message }}</span> @enderror

<label class="checkbox-row">
    <input name="enabled" type="checkbox" value="1" @checked(old('enabled', $provider?->enabled ?? true))>
    <span>Enable this provider for catalog sync and playback.</span>
</label>

<div class="form-actions">
    <button class="button button-primary" type="submit">{{ $provider ? 'Save changes' : 'Save provider' }}</button>
    <a class="button button-muted" href="{{ route('iptv.admin.providers.index') }}">Cancel</a>
</div>
