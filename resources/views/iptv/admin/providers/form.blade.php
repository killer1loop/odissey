@php($selectedProviderType = $provider?->config['api'] ?? old('provider_type', 'xtream'))

@csrf
@if ($provider ?? false)
    @method('PUT')
@endif

@if ($errors->any())
    <div class="errors" role="alert">
        <strong>Check the provider details below.</strong>
        <ul>
            @foreach ($errors->all() as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>
    </div>
@endif

<div class="form-grid form-grid-two">
    <label class="field" for="provider-name">
        <span>Provider name</span>
        <input id="provider-name" name="name" required maxlength="255" value="{{ old('name', $provider?->name) }}" placeholder="Living room TV">
        @error('name') <span class="field-error">{{ $message }}</span> @enderror
    </label>

    <label class="field" for="provider-type">
        <span>Connection type</span>
        @if($provider)
            <input type="hidden" name="provider_type" value="{{ $selectedProviderType }}">
        @endif
        <select id="provider-type" @unless($provider) name="provider_type" @endunless data-provider-type @disabled($provider) aria-describedby="provider-type-hint">
            <option value="xtream" @selected($selectedProviderType === 'xtream')>Xtream-compatible account</option>
            <option value="m3u" @selected($selectedProviderType === 'm3u')>Generic M3U + XMLTV</option>
        </select>
        <small id="provider-type-hint">{{ $provider ? 'Connection type is fixed after creation. Add a new provider to use another type.' : 'Choose the format supplied by your provider.' }}</small>
    </label>
</div>

<fieldset class="configuration-section" data-provider-fields="xtream" @if($selectedProviderType !== 'xtream') hidden @endif>
    <legend>Xtream account</legend>
    <p class="fieldset-intro">Enter the server address and account credentials supplied by your provider. Odissey encrypts them before storage.</p>

    <label class="field" for="provider-base-url">
        <span>{{ $provider ? 'New provider address' : 'Provider address' }} @if($provider)<small>Leave blank to keep stored value</small>@endif</span>
        <input id="provider-base-url" name="base_url" type="url" autocomplete="off" value="" placeholder="https://provider.example.com">
        @error('base_url') <span class="field-error">{{ $message }}</span> @enderror
    </label>

    <div class="form-grid form-grid-two">
        <label class="field" for="provider-username">
            <span>{{ $provider ? 'New username' : 'Username' }} @if($provider)<small>Optional</small>@endif</span>
            <input id="provider-username" name="username" autocomplete="off" value="">
            @error('username') <span class="field-error">{{ $message }}</span> @enderror
        </label>

        <label class="field" for="provider-password">
            <span>{{ $provider ? 'New password' : 'Password' }} @if($provider)<small>Optional</small>@endif</span>
            <input id="provider-password" name="password" type="password" autocomplete="new-password" value="">
            @error('password') <span class="field-error">{{ $message }}</span> @enderror
        </label>
    </div>
</fieldset>

<fieldset class="configuration-section" data-provider-fields="m3u" @if($selectedProviderType !== 'm3u') hidden @endif>
    <legend>M3U playlist and TV guide</legend>
    <p class="fieldset-intro">Use complete URLs supplied by the provider. XMLTV is optional but strongly recommended for the TV guide.</p>

    <label class="field" for="provider-playlist-url">
        <span>{{ $provider ? 'New M3U playlist URL' : 'M3U playlist URL' }} @if($provider)<small>Leave blank to keep stored value</small>@endif</span>
        <input id="provider-playlist-url" name="playlist_url" type="url" autocomplete="off" value="" placeholder="https://provider.example.com/channels.m3u">
        @error('playlist_url') <span class="field-error">{{ $message }}</span> @enderror
    </label>

    <label class="field" for="provider-xmltv-url">
        <span>{{ $provider ? 'New XMLTV guide URL' : 'XMLTV guide URL' }} <small>{{ $provider ? 'Leave blank to keep stored value' : 'Optional' }}</small></span>
        <input id="provider-xmltv-url" name="xmltv_url" type="url" autocomplete="off" value="" placeholder="https://provider.example.com/guide.xml">
        @error('xmltv_url') <span class="field-error">{{ $message }}</span> @enderror
    </label>
</fieldset>

<fieldset class="configuration-section">
    <legend>Playback and security</legend>

    <label class="field" for="provider-connections">
        <span>Maximum simultaneous streams</span>
        <input id="provider-connections" name="max_connections" type="number" min="1" max="100" value="{{ old('max_connections', $provider?->config['max_connections'] ?? 1) }}">
        <small>Keep this within the connection limit defined by your provider.</small>
    </label>

    <label class="checkbox-row consent-row">
        <input name="allow_insecure_http" type="checkbox" value="1" @checked(old('allow_insecure_http', $provider?->allow_insecure_http ?? false))>
        <span>
            <strong>Allow unencrypted HTTP</strong>
            I understand that credentials will be sent without transport encryption. Enable this only when the provider does not support HTTPS.
        </span>
    </label>
    @error('allow_insecure_http') <span class="field-error">{{ $message }}</span> @enderror

    <label class="checkbox-row preference-toggle">
        <input name="enabled" type="checkbox" value="1" @checked(old('enabled', $provider?->enabled ?? true))>
        <span>
            <strong>Enable this provider</strong>
            Include it in catalog synchronization, hourly guide refreshes, and playback.
        </span>
    </label>
</fieldset>

<div class="form-actions">
    <button class="button button-primary" type="submit">{{ $provider ? 'Save changes' : 'Save provider' }}</button>
    <a class="button button-muted" href="{{ route('iptv.admin.providers.index') }}">Cancel</a>
</div>
