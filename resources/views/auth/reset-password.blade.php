@extends('auth.layout')
@section('title','Choose password · Odissey')
@section('content')
<form method="POST" action="{{ route('password.update') }}" class="auth-card">@csrf<input type="hidden" name="token" value="{{ $token }}"><h1>Choose a new password</h1><label>Email<input name="email" type="email" required value="{{ $email }}"></label><label>New password<input name="password" type="password" required autocomplete="new-password"></label><label>Confirm password<input name="password_confirmation" type="password" required></label>@if($errors->any())<p role="alert">{{ $errors->first() }}</p>@endif<button type="submit">Reset password</button></form>
@endsection
