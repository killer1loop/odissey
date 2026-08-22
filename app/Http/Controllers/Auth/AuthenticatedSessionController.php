<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AuthenticatedSessionController extends Controller
{
    private const MAX_ATTEMPTS = 5;

    private const IP_MAX_ATTEMPTS = 20;

    private const DECAY_SECONDS = 60;

    private const DUMMY_PASSWORD_HASH = '$2y$12$op4mNVDE6rOZce4ztGLVM.bWDHUuq8dSAaiqcQNc8.bHgtSa9IQdq';

    public function create(): View
    {
        return view('auth.login');
    }

    public function store(LoginRequest $request): RedirectResponse
    {
        $throttleKey = $this->throttleKey($request);
        $ipThrottleKey = $this->ipThrottleKey($request);

        if (
            RateLimiter::tooManyAttempts($throttleKey, self::MAX_ATTEMPTS)
            || RateLimiter::tooManyAttempts($ipThrottleKey, self::IP_MAX_ATTEMPTS)
        ) {
            $seconds = max(
                RateLimiter::availableIn($throttleKey),
                RateLimiter::availableIn($ipThrottleKey),
            );

            throw ValidationException::withMessages([
                'email' => trans('auth.throttle', [
                    'seconds' => $seconds,
                    'minutes' => (int) ceil($seconds / 60),
                ]),
            ]);
        }

        $credentials = [
            'email' => $request->string('email')->toString(),
            'password' => $request->string('password')->toString(),
            'is_active' => true,
        ];

        // Unknown accounts skip bcrypt entirely, so a fast failure would let
        // response timing enumerate registered emails; burn an equivalent
        // hash comparison before failing.
        if (! User::query()->where('email', $credentials['email'])->exists()) {
            Hash::check(
                $credentials['password'],
                self::DUMMY_PASSWORD_HASH,
            );
        }

        $authenticated = Auth::attempt($credentials, $request->boolean('remember'));

        if (! $authenticated) {
            RateLimiter::hit($throttleKey, self::DECAY_SECONDS);
            RateLimiter::hit($ipThrottleKey, self::DECAY_SECONDS);

            throw ValidationException::withMessages([
                'email' => trans('auth.failed'),
            ]);
        }

        RateLimiter::clear($throttleKey);
        $request->session()->regenerate();

        return redirect()->intended(route('home'));
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }

    private function throttleKey(LoginRequest $request): string
    {
        return Str::transliterate(
            Str::lower($request->string('email')->toString()).'|'.$request->ip()
        );
    }

    private function ipThrottleKey(LoginRequest $request): string
    {
        return 'login-ip|'.$request->ip();
    }
}
