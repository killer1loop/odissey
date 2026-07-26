@extends('auth.layout')

@section('title', 'Reset password')

@section('content')
    <section class="auth-card" aria-labelledby="reset-heading">
        <h1 id="reset-heading">Reset password</h1>
        <p class="auth-intro">Enter your account email. The response never reveals whether an account exists.</p>

        @if (session('status'))
            <p class="notice notice-success" role="status">{{ session('status') }}</p>
        @endif
        @error('email')
            <p class="notice notice-error" role="alert">{{ $message }}</p>
        @enderror

        <form method="POST" action="{{ route('password.email') }}">
            @csrf
            <label class="field" for="email">
                Email address
                <input id="email" name="email" type="email" required autocomplete="email" autofocus>
            </label>
            <button class="button button-primary auth-submit" type="submit">Send reset link</button>
        </form>
        <a class="auth-back" href="{{ route('login') }}">Back to sign in</a>
    </section>
@endsection
