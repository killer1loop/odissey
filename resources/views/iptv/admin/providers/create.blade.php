@extends('layouts.app')

@section('title', 'Add IPTV provider · Odissey')

@section('content')
    <section class="page-section narrow">
        <header class="page-header">
            <div>
                <p class="eyebrow">IPTV onboarding</p>
                <h1>Add provider</h1>
                <p>Connect an Xtream-compatible account or a generic M3U playlist. Secrets are encrypted before storage.</p>
            </div>
        </header>

        <form class="settings-form onboarding-form" method="POST" action="{{ route('iptv.admin.providers.store') }}" autocomplete="off" data-provider-config>
            @include('iptv.admin.providers.form', ['provider' => null])
        </form>
    </section>
@endsection
