@extends('layouts.app')

@section('title', 'Users · Odissey')

@section('content')
    <section class="page-section">
        <header class="page-header">
            <div>
                <p class="eyebrow">Administration</p>
                <h1>User accounts</h1>
                <p>Create household accounts and manage access without sharing the administrator password.</p>
            </div>
        </header>

        @if (session('status'))
            <p class="notice notice-success" role="status">{{ session('status') }}</p>
        @endif

        @if ($errors->any())
            <div class="notice notice-error" role="alert">
                <ul>
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <div class="settings-grid">
            <section class="panel" aria-labelledby="create-user-heading">
                <h2 id="create-user-heading">Add a user</h2>
                <form class="settings-form settings-form-plain" method="POST" action="{{ route('admin.users.store') }}">
                    @csrf

                    <label class="field" for="name">
                        Display name
                        <input id="name" name="name" value="{{ old('name') }}" required autocomplete="off">
                    </label>

                    <label class="field" for="email">
                        Email address
                        <input id="email" type="email" name="email" value="{{ old('email') }}" required autocomplete="off">
                    </label>

                    <label class="field" for="password">
                        Temporary password
                        <input id="password" type="password" name="password" required autocomplete="new-password">
                    </label>

                    <label class="field" for="password_confirmation">
                        Confirm password
                        <input id="password_confirmation" type="password" name="password_confirmation" required autocomplete="new-password">
                    </label>

                    <div class="form-actions">
                        <button class="button button-primary" type="submit">Create user</button>
                    </div>
                </form>
            </section>

            <section class="panel" aria-labelledby="existing-users-heading">
                <h2 id="existing-users-heading">Existing users</h2>
                <div class="user-list">
                    @foreach ($users as $user)
                        <article class="user-row">
                            <div class="avatar" aria-hidden="true">{{ mb_strtoupper(mb_substr($user->name, 0, 1)) }}</div>
                            <div class="user-copy">
                                <strong>{{ $user->name }}</strong>
                                <span>{{ $user->email }}</span>
                            </div>
                            @if ($user->is_admin)
                                <span class="badge">Administrator</span>
                            @elseif (! $user->is_active)
                                <span class="badge badge-muted">Disabled</span>
                            @else
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
    </section>
@endsection
