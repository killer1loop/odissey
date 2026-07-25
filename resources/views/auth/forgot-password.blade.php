@extends('auth.layout')
@section('title','Reset password · Odissey')
@section('content')
<form method="POST" action="{{ route('password.email') }}" class="auth-card">@csrf<h1>Reset password</h1><p>Enter your account email. Responses do not reveal whether an account exists.</p><label>Email<input name="email" type="email" required autocomplete="email"></label>@if(session('status'))<p role="status">{{ session('status') }}</p>@endif @error('email')<p role="alert">{{ $message }}</p>@enderror<button type="submit">Send reset link</button><a href="{{ route('login') }}">Back to sign in</a></form>
@endsection
