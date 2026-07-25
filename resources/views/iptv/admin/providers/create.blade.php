@extends('layouts.app')

@section('title', 'Add IPTV provider · Odissey')

@section('content')
    @include('iptv.styles')

    <section class="iptv-page">
        <header class="iptv-header">
            <div>
                <p class="eyebrow">IPTV onboarding</p>
                <h1>Add provider</h1>
                <p>Enter Xtream-compatible account details. Secrets are encrypted before storage.</p>
            </div>
        </header>

        <form class="iptv-form" method="POST" action="{{ route('iptv.admin.providers.store') }}" autocomplete="off">
            @include('iptv.admin.providers.form', ['provider' => null])
        </form>
    </section>
@endsection
