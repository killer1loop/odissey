@extends('layouts.app')

@section('title', 'Edit IPTV provider · Odissey')

@section('content')
    <section class="page-section narrow">
        <header class="page-header">
            <div>
                <p class="eyebrow">IPTV administration</p>
                <h1>Edit {{ $provider->name }}</h1>
                <p>Stored addresses and credentials are intentionally not revealed.</p>
            </div>
        </header>

        <form class="settings-form onboarding-form" method="POST" action="{{ route('iptv.admin.providers.update', $provider) }}" autocomplete="off" data-provider-config>
            @include('iptv.admin.providers.form', ['provider' => $provider])
        </form>
    </section>
@endsection
