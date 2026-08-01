@extends('layouts.app')

@php($selectedType = old('type', 'local'))

@section('title', 'Add media source · Odissey')

@section('content')
    <section class="page-section narrow" aria-labelledby="source-heading">
        <header class="page-header">
            <div>
                <p class="eyebrow">Read-only onboarding</p>
                <h1 id="source-heading">Add media source</h1>
                <p>Connect a mounted folder, S3-compatible bucket, or WebDAV collection. Odissey indexes the library without modifying source files.</p>
            </div>
        </header>

        @if ($errors->any())
            <div class="notice notice-error" role="alert">
                <strong>The source could not be validated.</strong>
                <ul>
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <form
            method="POST"
            action="{{ route('media.admin.sources.store') }}"
            class="settings-form onboarding-form"
            data-source-config
            autocomplete="off"
        >
            @csrf

            <div class="form-grid form-grid-two">
                <label class="field" for="source-name">
                    <span>Source name</span>
                    <input id="source-name" name="name" required maxlength="100" value="{{ old('name') }}" placeholder="Family movies">
                </label>

                <label class="field" for="source-type">
                    <span>Connection type</span>
                    <select id="source-type" name="type" required data-source-type>
                        <option value="local" @selected($selectedType === 'local')>Local mount</option>
                        <option value="s3" @selected($selectedType === 's3')>S3-compatible bucket</option>
                        <option value="webdav" @selected($selectedType === 'webdav')>WebDAV collection</option>
                    </select>
                </label>
            </div>

            <fieldset class="configuration-section" data-source-fields="local" @if($selectedType !== 'local') hidden @endif>
                <legend>Local mount</legend>
                <p class="fieldset-intro">Use a path mounted inside the Odissey container. The mount should be read-only.</p>
                <label class="field" for="source-path">
                    <span>Container path</span>
                    <input id="source-path" name="path" value="{{ old('path') }}" placeholder="/media/movies">
                    <small>Docker deployments typically mount a host folder beneath <code>/media</code>.</small>
                </label>
            </fieldset>

            <fieldset class="configuration-section" data-source-fields="s3" @if($selectedType !== 's3') hidden @endif>
                <legend>S3-compatible bucket</legend>
                <p class="fieldset-intro">Works with AWS S3 and compatible object-storage services. Use read-only credentials scoped to this bucket.</p>
                <div class="form-grid form-grid-two">
                    <label class="field" for="source-endpoint">
                        <span>Endpoint</span>
                        <input id="source-endpoint" name="endpoint" type="url" value="{{ old('endpoint') }}" placeholder="https://s3.example.com">
                    </label>
                    <label class="field" for="source-region">
                        <span>Region</span>
                        <input id="source-region" name="region" value="{{ old('region', 'us-east-1') }}" placeholder="us-east-1">
                    </label>
                    <label class="field" for="source-bucket">
                        <span>Bucket</span>
                        <input id="source-bucket" name="bucket" value="{{ old('bucket') }}" placeholder="media-library">
                    </label>
                    <label class="field" for="source-prefix">
                        <span>Prefix <small>Optional</small></span>
                        <input id="source-prefix" name="prefix" value="{{ old('prefix') }}" placeholder="movies/">
                    </label>
                    <label class="field" for="source-access-key">
                        <span>Access key</span>
                        <input id="source-access-key" name="access_key" value="" autocomplete="off">
                    </label>
                    <label class="field" for="source-secret-key">
                        <span>Secret key</span>
                        <input id="source-secret-key" type="password" name="secret_key" value="" autocomplete="new-password">
                    </label>
                </div>
            </fieldset>

            <fieldset class="configuration-section" data-source-fields="webdav" @if($selectedType !== 'webdav') hidden @endif>
                <legend>WebDAV collection</legend>
                <p class="fieldset-intro">Enter the complete collection URL and a read-only account when your server supports one.</p>
                <label class="field" for="source-url">
                    <span>Collection URL</span>
                    <input id="source-url" name="url" type="url" value="{{ old('url') }}" placeholder="https://example.com/remote.php/dav/files/user/Videos">
                </label>
                <div class="form-grid form-grid-two">
                    <label class="field" for="source-username">
                        <span>Username</span>
                        <input id="source-username" name="username" value="" autocomplete="off">
                    </label>
                    <label class="field" for="source-password">
                        <span>Password</span>
                        <input id="source-password" type="password" name="password" value="" autocomplete="new-password">
                    </label>
                </div>
            </fieldset>

            <label class="checkbox-row consent-row">
                <input type="checkbox" name="allow_private_network" value="1" @checked(old('allow_private_network'))>
                <span>
                    <strong>Trust this private or HTTP endpoint</strong>
                    Allow access only when this source is on a network you control or cannot provide HTTPS.
                </span>
            </label>

            <div class="form-actions">
                <button class="button button-primary" type="submit">Validate and scan</button>
                <a class="button button-muted" href="{{ route('media.admin.sources.index') }}">Cancel</a>
            </div>
        </form>
    </section>
@endsection
