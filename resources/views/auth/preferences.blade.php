@extends('layouts.app')

@section('title', 'Preferences · Odissey')

@section('content')
    <section class="page-section narrow" aria-labelledby="preferences-heading">
        <header class="page-header">
            <div>
                <p class="eyebrow">Your profile</p>
                <h1 id="preferences-heading">Playback preferences</h1>
                <p>These defaults apply only to {{ auth()->user()->name }}. Viewing progress and history remain separate for every user.</p>
            </div>
        </header>

        @if (session('status'))
            <p class="notice notice-success" role="status">{{ session('status') }}</p>
        @endif

        <form class="settings-form preference-form" method="POST" action="{{ route('preferences.update') }}">
            @csrf
            @method('PUT')

            <div class="form-grid form-grid-two">
                <label class="field" for="preference-timezone">
                    <span>Timezone</span>
                    <select id="preference-timezone" name="timezone">
                        @foreach ($timezones as $timezone)
                            <option @selected(auth()->user()->timezone === $timezone)>{{ $timezone }}</option>
                        @endforeach
                    </select>
                    <small>Used for the TV guide and playback history.</small>
                </label>

                <label class="field" for="preference-quality">
                    <span>Preferred quality</span>
                    <select id="preference-quality" name="preferred_quality">
                        @foreach (['auto', 'original', '1080p', '720p'] as $quality)
                            <option @selected((auth()->user()->preferences['preferred_quality'] ?? 'auto') === $quality) value="{{ $quality }}">
                                {{ $quality === 'auto' ? 'Automatic' : ucfirst($quality) }}
                            </option>
                        @endforeach
                    </select>
                    <small>Automatic adapts to the available stream and device.</small>
                </label>
            </div>

            <label class="checkbox-row preference-toggle">
                <input name="autoplay" type="checkbox" value="1" @checked(auth()->user()->preferences['autoplay'] ?? false)>
                <span>
                    <strong>Autoplay the next episode</strong>
                    Continue directly when another episode is available.
                </span>
            </label>

            <div class="form-actions">
                <button class="button button-primary" type="submit">Save preferences</button>
            </div>
        </form>
    </section>
@endsection
