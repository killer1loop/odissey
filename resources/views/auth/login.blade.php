@extends('auth.layout')

@section('title', 'Sign in')

@section('content')
    <section class="card" aria-labelledby="login-heading">
        <h1 id="login-heading">Sign in</h1>
        <p>Use an account created by your Odissey administrator.</p>

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

            <label class="check" for="remember">
                <input id="remember" type="checkbox" name="remember" value="1" @checked(old('remember'))>
                <span>Keep me signed in</span>
            </label>

            <button class="button" type="submit">Sign in</button>
        </form>
    </section>
@endsection
