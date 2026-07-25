@extends('layouts.app')

@section('title', 'IPTV providers · Odissey')

@section('content')
    @include('iptv.styles')

    <section class="iptv-page">
        <header class="iptv-header">
            <div>
                <p class="eyebrow">Administration</p>
                <h1>IPTV providers</h1>
                <p>Connection details are encrypted and never displayed after they are saved.</p>
            </div>
            <a class="button button-primary" href="{{ route('iptv.admin.providers.create') }}">Add provider</a>
        </header>

        @if (session('status'))
            <p class="iptv-notice" role="status">{{ session('status') }}</p>
        @endif

        <div class="iptv-grid">
            @forelse ($providers as $provider)
                <article class="iptv-card">
                    <h2>{{ $provider->name }}</h2>
                    <div class="iptv-meta">
                        <span>{{ $provider->enabled ? 'Enabled' : 'Disabled' }}</span>
                        <span>·</span>
                        <span>{{ $provider->sync_status }}</span>
                        <span>·</span>
                        <span>{{ $provider->allow_insecure_http ? 'HTTP consented' : 'HTTPS required' }}</span>
                    </div>
                    <p>{{ number_format($provider->channels_count) }} channels in {{ number_format($provider->groups_count) }} groups</p>
                    @if ($provider->last_synced_at)
                        <p>Catalog synced {{ $provider->last_synced_at->diffForHumans() }}.</p>
                    @endif
                    @if ($provider->last_error_code)
                        <p class="iptv-error">Last sync did not finish ({{ $provider->last_error_code }}).</p>
                    @endif

                    <div class="iptv-card-actions">
                        <a class="button button-muted" href="{{ route('iptv.admin.providers.edit', $provider) }}">Edit</a>
                        <form method="POST" action="{{ route('iptv.admin.providers.sync', $provider) }}">
                            @csrf
                            <button class="button button-muted" type="submit">Sync channels</button>
                        </form>
                        <form method="POST" action="{{ route('iptv.admin.providers.guide', $provider) }}">
                            @csrf
                            <button class="button button-muted" type="submit">Sync guide</button>
                        </form>
                        <form method="POST" action="{{ route('iptv.admin.providers.destroy', $provider) }}">
                            @csrf
                            @method('DELETE')
                            <button class="button button-muted" type="submit">Remove</button>
                        </form>
                    </div>
                </article>
            @empty
                <p class="iptv-empty">No IPTV provider has been configured.</p>
            @endforelse
        </div>
    </section>
@endsection
