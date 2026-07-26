@extends('auth.layout')

@section('title', 'Choose password')

@section('content')
    <section class="auth-card" aria-labelledby="password-heading">
        <h1 id="password-heading">Choose a new password</h1>
        <p class="auth-intro">Use a unique password for this Odissey account.</p>

        @if ($errors->any())
            <p class="notice notice-error" role="alert">{{ $errors->first() }}</p>
        @endif

        <form method="POST" action="{{ route('password.update') }}">
            @csrf
            <input type="hidden" name="token" value="{{ $token }}">
            <label class="field" for="email">
                Email address
                <input id="email" name="email" type="email" required value="{{ $email }}">
            </label>
            <label class="field" for="password">
                New password
                <input id="password" name="password" type="password" required autocomplete="new-password">
            </label>
            <label class="field" for="password-confirmation">
                Confirm password
                <input id="password-confirmation" name="password_confirmation" type="password" required autocomplete="new-password">
            </label>
            <button class="button button-primary auth-submit" type="submit">Reset password</button>
        </form>
    </section>
@endsection
