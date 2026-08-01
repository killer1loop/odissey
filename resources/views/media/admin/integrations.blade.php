@extends('layouts.app')

@section('title', 'Metadata & captions · Odissey')

@section('content')
    <section class="page-section narrow" aria-labelledby="integrations-heading">
        <header class="page-header">
            <div>
                <p class="eyebrow">Administration</p>
                <h1 id="integrations-heading">Metadata & captions</h1>
                <p>Connect free-access discovery services for titles, artwork, episode information, and captions. Stored keys are encrypted and never shown again.</p>
            </div>
        </header>

        @if (session('status'))
            <p class="notice notice-success" role="status">{{ session('status') }}</p>
        @endif

        @if ($errors->any())
            <div class="notice notice-error" role="alert">
                <strong>Review the integration settings.</strong>
                <ul>
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <form class="settings-form integration-form" method="POST" action="{{ route('media.admin.integrations.update') }}">
            @csrf
            @method('PUT')

            <fieldset class="integration-section">
                <legend>Movies and series</legend>
                <p class="fieldset-intro">Artwork and metadata are matched automatically while libraries are scanned.</p>

                <div class="integration-provider">
                    <div class="integration-provider-heading">
                        <div>
                            <strong>TMDB</strong>
                            <span>Movies, series, posters, and backdrops</span>
                        </div>
                        <span class="connection-status {{ $configured['tmdb_api_token'] ? 'is-connected' : '' }}">
                            {{ $configured['tmdb_api_token'] ? 'Configured' : 'Optional key' }}
                        </span>
                    </div>
                    <label class="field" for="tmdb-token">
                        <span>Read access token</span>
                        <input id="tmdb-token" type="password" name="tmdb_api_token" autocomplete="new-password" placeholder="Leave blank to keep the stored token">
                    </label>
                    @if ($configured['tmdb_api_token'])
                        <label class="checkbox-row">
                            <input type="checkbox" name="clear[]" value="tmdb_api_token">
                            <span>Remove the stored TMDB token</span>
                        </label>
                    @endif
                </div>

                <div class="integration-provider integration-provider-passive">
                    <div class="integration-provider-heading">
                        <div>
                            <strong>TVmaze</strong>
                            <span>Series and episode matching</span>
                        </div>
                        <span class="connection-status is-connected">Automatic</span>
                    </div>
                    <p>No account or key is required.</p>
                </div>
            </fieldset>

            <fieldset class="integration-section">
                <legend>Caption services</legend>
                <p class="fieldset-intro">Odissey searches configured languages and stores only downloaded caption files.</p>

                <div class="form-grid form-grid-two">
                    <div class="integration-provider">
                        <div class="integration-provider-heading">
                            <strong>SubDL</strong>
                            <span class="connection-status {{ $configured['subdl_api_key'] ? 'is-connected' : '' }}">
                                {{ $configured['subdl_api_key'] ? 'Configured' : 'Not configured' }}
                            </span>
                        </div>
                        <label class="field" for="subdl-key">
                            <span>API key</span>
                            <input id="subdl-key" type="password" name="subdl_api_key" autocomplete="new-password" placeholder="Leave blank to keep stored key">
                        </label>
                        @if ($configured['subdl_api_key'])
                            <label class="checkbox-row">
                                <input type="checkbox" name="clear[]" value="subdl_api_key">
                                <span>Remove stored key</span>
                            </label>
                        @endif
                    </div>

                    <div class="integration-provider">
                        <div class="integration-provider-heading">
                            <strong>OpenSubtitles</strong>
                            <span class="connection-status {{ $configured['opensubtitles_api_key'] ? 'is-connected' : '' }}">
                                {{ $configured['opensubtitles_api_key'] ? 'Configured' : 'Not configured' }}
                            </span>
                        </div>
                        <label class="field" for="opensubtitles-key">
                            <span>API key</span>
                            <input id="opensubtitles-key" type="password" name="opensubtitles_api_key" autocomplete="new-password" placeholder="Leave blank to keep stored key">
                        </label>
                        @if ($configured['opensubtitles_api_key'])
                            <label class="checkbox-row">
                                <input type="checkbox" name="clear[]" value="opensubtitles_api_key">
                                <span>Remove stored key</span>
                            </label>
                        @endif
                    </div>
                </div>

                <label class="field" for="caption-languages">
                    <span>Preferred languages</span>
                    <input id="caption-languages" name="caption_languages" required value="{{ old('caption_languages', $languages) }}" placeholder="en,de,it">
                    <small>Use comma-separated ISO language codes in preference order.</small>
                </label>
            </fieldset>

            <div class="form-actions">
                <button class="button button-primary" type="submit">Save integrations</button>
            </div>
        </form>
    </section>
@endsection
