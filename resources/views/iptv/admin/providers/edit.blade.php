@extends('layouts.app')

@section('title', 'Edit IPTV provider · Odissey')

@section('content')
    @include('iptv.styles')

    <section class="iptv-page">
        <header class="iptv-header">
            <div>
                <p class="eyebrow">IPTV administration</p>
                <h1>Edit {{ $provider->name }}</h1>
                <p>Stored addresses and credentials are intentionally not revealed.</p>
            </div>
        </header>

        <form class="iptv-form" method="POST" action="{{ route('iptv.admin.providers.update', $provider) }}" autocomplete="off">
            @include('iptv.admin.providers.form', ['provider' => $provider])
        </form>
    </section>
@endsection
