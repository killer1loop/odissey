@extends('layouts.app')
@section('title', 'Media sources · Odissey')
@section('content')
<section class="page-section">
    <header class="page-header"><div><p class="eyebrow">Administration</p><h1>Media sources</h1><p>Read-only local, S3-compatible, and WebDAV libraries.</p></div><a class="button button-primary" href="{{ route('media.admin.sources.create') }}">Add source</a></header>
    @if(session('status')) <p class="notice notice-success">{{ session('status') }}</p> @endif
    <div class="admin-list">
    @forelse($sources as $source)
        <article class="admin-card"><div><strong>{{ $source->name }}</strong><p>{{ strtoupper($source->type) }} · {{ $source->items_count }} items · {{ $source->scan_status }}</p>@if($source->type === 'iptv')<p>Managed automatically by its IPTV provider catalog sync.</p>@endif @if($source->last_error_code)<p role="alert">The last scan failed safely.</p>@endif</div>
        @if($source->type !== 'iptv')<div class="card-actions"><form method="POST" action="{{ route('media.admin.sources.scan', $source) }}">@csrf<button class="button" type="submit">Scan</button></form><form method="POST" action="{{ route('media.admin.sources.destroy', $source) }}">@csrf @method('DELETE')<button class="button button-danger" type="submit">Remove metadata</button></form></div>@endif</article>
    @empty <div class="empty-state"><h2>No media sources</h2><p>Add a read-only source to build your library.</p></div>@endforelse
    </div>
</section>
@endsection
