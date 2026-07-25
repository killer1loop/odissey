@extends('auth.layout')

@section('title', 'Users')
@section('shell', 'admin-shell')

@section('content')
    <div class="topline">
        <div>
            <h1>User administration</h1>
            <p>Create household accounts and revoke access without sharing the administrator password.</p>
        </div>

        <form method="POST" action="{{ route('logout') }}">
            @csrf
            <button class="button button-secondary" type="submit">Sign out</button>
        </form>
    </div>

    @if (session('status'))
        <div class="status" role="status">{{ session('status') }}</div>
    @endif

    @if ($errors->any())
        <div class="errors" role="alert">
            <ul>
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <div class="grid">
        <section class="card" aria-labelledby="create-user-heading">
            <h2 id="create-user-heading">Add a user</h2>

            <form method="POST" action="{{ route('admin.users.store') }}">
                @csrf

                <div class="field">
                    <label for="name">Display name</label>
                    <input id="name" name="name" value="{{ old('name') }}" required autocomplete="off">
                </div>

                <div class="field">
                    <label for="email">Email address</label>
                    <input id="email" type="email" name="email" value="{{ old('email') }}" required autocomplete="off">
                </div>

                <div class="field">
                    <label for="password">Temporary password</label>
                    <input id="password" type="password" name="password" required autocomplete="new-password">
                </div>

                <div class="field">
                    <label for="password_confirmation">Confirm password</label>
                    <input id="password_confirmation" type="password" name="password_confirmation" required autocomplete="new-password">
                </div>

                <button class="button" type="submit">Create user</button>
            </form>
        </section>

        <section class="card" aria-labelledby="existing-users-heading">
            <h2 id="existing-users-heading">Existing users</h2>

            <div class="users" style="margin-top: 1rem">
                @foreach ($users as $user)
                    <article class="user">
                        <div>
                            <strong>
                                {{ $user->name }}
                                @if ($user->is_admin)
                                    <span class="badge">Administrator</span>
                                @elseif (! $user->is_active)
                                    <span class="badge">Disabled</span>
                                @endif
                            </strong>
                            <span>{{ $user->email }}</span>
                        </div>

                        @if (! $user->is_admin && $user->is_active)
                            <form method="POST" action="{{ route('admin.users.disable', $user) }}">
                                @csrf
                                @method('PATCH')
                                <button class="button button-danger" type="submit">Disable</button>
                            </form>
                        @endif
                    </article>
                @endforeach
            </div>
        </section>
    </div>
@endsection
