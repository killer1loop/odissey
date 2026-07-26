@extends('layouts.app')

@section('title', 'IPTV providers · Odissey')

@section('content')
    <section class="page-section">
        <header class="page-header">
            <div>
                <p class="eyebrow">Administration</p>
                <h1>IPTV providers</h1>
                <p>Connection details are encrypted and never displayed after they are saved.</p>
            </div>
            <a class="button button-primary" href="{{ route('iptv.admin.providers.create') }}">Add provider</a>
        </header>

        @if (session('status'))
            <p class="notice notice-success" role="status">{{ session('status') }}</p>
        @endif

        <div class="admin-list">
            @forelse ($providers as $provider)
                <article class="admin-card">
                    <div>
                    <h2>{{ $provider->name }}</h2>
                    <div class="meta-list">
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
                        <p class="field-error">Last sync did not finish ({{ $provider->last_error_code }}).</p>
                    @endif
                    </div>

                    <div class="card-actions">
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
                            <button class="button button-danger" type="submit">Remove</button>
                        </form>
                    </div>
                </article>
            @empty
                <div class="empty-state"><h2>No IPTV providers</h2><p>Add a provider to import channels and guide data.</p></div>
            @endforelse
        </div>
    </section>
@endsection
