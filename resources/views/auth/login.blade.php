@extends('auth.layout')

@section('title', 'Sign in')

@section('content')
    <section class="auth-card" aria-labelledby="login-heading">
        <h1 id="login-heading">Sign in</h1>
        <p class="auth-intro">Access your private media libraries and Live TV.</p>

        @if ($errors->any())
            <div class="errors" role="alert">
                <ul>
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <form method="POST" action="{{ route('login.store') }}">
            @csrf

            <div class="field">
                <label for="email">Email address</label>
                <input id="email" type="email" name="email" value="{{ old('email') }}" required autocomplete="username" autofocus>
            </div>

            <div class="field">
                <label for="password">Password</label>
                <input id="password" type="password" name="password" required autocomplete="current-password">
            </div>

            <div class="auth-form-row">
                <label class="check" for="remember">
                    <input id="remember" type="checkbox" name="remember" value="1" @checked(old('remember'))>
                    <span>Keep me signed in</span>
                </label>
                <a href="{{ route('password.request') }}">Forgot password?</a>
            </div>
            <button class="button button-primary auth-submit" type="submit">Sign in</button>
        </form>
    </section>
@endsection
