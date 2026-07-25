@extends('auth.layout')

@section('title', 'First-launch setup')

@section('content')
    <section class="card" aria-labelledby="setup-heading">
        <h1 id="setup-heading">Create the first administrator</h1>
        <p>This one-time setup creates the only initial account. Additional users can be added from the protected administration area.</p>

        @if ($errors->any())
            <div class="errors" role="alert">
                <ul>
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <form method="POST" action="{{ route('setup.store') }}">
            @csrf

            <div class="field">
                <label for="name">Display name</label>
                <input id="name" name="name" value="{{ old('name') }}" required autocomplete="name" autofocus>
            </div>

            <div class="field">
                <label for="email">Email address</label>
                <input id="email" type="email" name="email" value="{{ old('email') }}" required autocomplete="username">
            </div>

            <div class="field">
                <label for="password">Password</label>
                <input id="password" type="password" name="password" required autocomplete="new-password">
            </div>

            <div class="field">
                <label for="password_confirmation">Confirm password</label>
                <input id="password_confirmation" type="password" name="password_confirmation" required autocomplete="new-password">
            </div>

            @if ($requiresSetupToken)
                <div class="field">
                    <label for="setup_token">Server setup token</label>
                    <input id="setup_token" type="password" name="setup_token" required autocomplete="off">
                </div>
            @endif

            <button class="button" type="submit">Create administrator</button>
        </form>
    </section>
@endsection
