@extends('layouts.app')

@section('title', 'Add IPTV provider · Odissey')

@section('content')
    <section class="page-section narrow">
        <header class="page-header">
            <div>
                <p class="eyebrow">IPTV onboarding</p>
                <h1>Add provider</h1>
                <p>Enter Xtream-compatible account details. Secrets are encrypted before storage.</p>
            </div>
        </header>

        <form class="settings-form" method="POST" action="{{ route('iptv.admin.providers.store') }}" autocomplete="off">
            @include('iptv.admin.providers.form', ['provider' => null])
        </form>
    </section>
@endsection
