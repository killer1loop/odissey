<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\ApiProblem;
use App\Http\Controllers\Controller;
use App\Models\NativeClientSession;
use App\Models\User;
use App\Services\Api\NativeTokenService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    private const LOGIN_ATTEMPTS = 5;

    private const IP_ATTEMPTS = 20;

    private const DUMMY_PASSWORD_HASH = '$2y$12$op4mNVDE6rOZce4ztGLVM.bWDHUuq8dSAaiqcQNc8.bHgtSa9IQdq';

    public function login(
        Request $request,
        NativeTokenService $tokens,
    ): JsonResponse {
        $validated = $this->validateLogin($request);
        $login = Str::lower(trim($validated['email']));
        $identityKey = 'native-login|'.Str::transliterate($login)
            .'|'.$request->ip();
        $ipKey = 'native-login-ip|'.$request->ip();

        if (
            RateLimiter::tooManyAttempts($identityKey, self::LOGIN_ATTEMPTS)
            || RateLimiter::tooManyAttempts($ipKey, self::IP_ATTEMPTS)
        ) {
            $seconds = max(
                RateLimiter::availableIn($identityKey),
                RateLimiter::availableIn($ipKey),
            );

            return ApiProblem::response(
                429,
                'rate_limited',
                'Too many attempts',
                'Too many authentication attempts. Try again later.',
                ['retryAfter' => $seconds],
                ['Retry-After' => (string) $seconds],
            );
        }

        $user = User::query()
            ->whereRaw('LOWER(email) = ?', [$login])
            ->first();
        $passwordValid = Hash::check(
            $validated['password'],
            $user?->password ?? self::DUMMY_PASSWORD_HASH,
        );
        if (
            $user === null
            || ! $user->isActive()
            || ! $passwordValid
        ) {
            RateLimiter::hit($identityKey, 60);
            RateLimiter::hit($ipKey, 60);

            return ApiProblem::response(
                401,
                'invalid_credentials',
                'Authentication failed',
                'The supplied credentials are invalid.',
                headers: ['WWW-Authenticate' => 'Bearer'],
            );
        }

        RateLimiter::clear($identityKey);
        $issued = $tokens->issue($user, $validated['device']);

        return response()->json([
            ...$tokens->responsePayload(
                $issued['session'],
                $issued['accessToken'],
                $issued['refreshToken'],
            ),
            'user' => $this->userPayload($user),
            'profiles' => [$this->profilePayload($user)],
            'activeProfile' => (string) $user->getKey(),
        ], headers: ['Cache-Control' => 'no-store']);
    }

    public function refresh(
        Request $request,
        NativeTokenService $tokens,
    ): JsonResponse {
        $validated = $request->validate([
            'refreshToken' => ['required', 'string', 'max:200'],
        ]);
        $issued = $tokens->rotate($validated['refreshToken']);

        return response()->json([
            ...$tokens->responsePayload(
                $issued['session'],
                $issued['accessToken'],
                $issued['refreshToken'],
            ),
            'user' => $this->userPayload($issued['session']->user),
            'profiles' => [
                $this->profilePayload($issued['session']->user),
            ],
            'activeProfile' => (string) $issued['session']->user_id,
        ], headers: ['Cache-Control' => 'no-store']);
    }

    public function logout(Request $request): JsonResponse
    {
        $this->clientSession($request)->revoke();

        return response()->json(
            ['revoked' => true],
            headers: ['Cache-Control' => 'no-store'],
        );
    }

    public function sessions(Request $request): JsonResponse
    {
        $current = $this->clientSession($request);
        $sessions = NativeClientSession::query()
            ->whereBelongsTo($request->user())
            ->whereNull('revoked_at')
            ->where('refresh_expires_at', '>', now())
            ->latest('last_used_at')
            ->latest('created_at')
            ->limit(50)
            ->get()
            ->map(fn (NativeClientSession $session): array => [
                'id' => (string) $session->getKey(),
                'deviceName' => $session->device_name,
                'platform' => $session->platform,
                'appVersion' => $session->app_version,
                'osVersion' => $session->os_version,
                'createdAt' => $session->created_at->utc()->toIso8601String(),
                'lastUsedAt' => $session->last_used_at?->utc()->toIso8601String(),
                'current' => $session->is($current),
            ])
            ->values();

        return response()->json(['data' => $sessions], headers: [
            'Cache-Control' => 'private, max-age=15',
        ]);
    }

    public function revokeSession(
        Request $request,
        string $session,
    ): JsonResponse {
        $target = NativeClientSession::query()
            ->whereBelongsTo($request->user())
            ->findOrFail($session);
        $target->revoke();

        return response()->json(['revoked' => true], headers: [
            'Cache-Control' => 'no-store',
        ]);
    }

    public function setup(
        Request $request,
        NativeTokenService $tokens,
    ): JsonResponse {
        abort_if(User::query()->exists(), 404);
        $validated = $request->validate([
            'setupToken' => ['nullable', 'string', 'max:255'],
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users'],
            'password' => [
                'required',
                'string',
                'max:255',
                Password::min(12)->letters()->mixedCase()->numbers(),
            ],
            'passwordConfirmation' => [
                'required',
                'same:password',
            ],
            'device' => $this->deviceRules()['device'],
            'device.installationId' => $this->deviceRules()['device.installationId'],
            'device.deviceName' => $this->deviceRules()['device.deviceName'],
            'device.platform' => $this->deviceRules()['device.platform'],
            'device.appVersion' => $this->deviceRules()['device.appVersion'],
            'device.osVersion' => $this->deviceRules()['device.osVersion'],
        ]);

        if (app()->environment('production')) {
            $configured = (string) config('odissey-auth.setup_token');
            $provided = (string) ($validated['setupToken'] ?? '');
            if (
                $configured === ''
                || $provided === ''
                || ! hash_equals($configured, $provided)
            ) {
                throw ValidationException::withMessages([
                    'setupToken' => 'The setup token is invalid.',
                ]);
            }
        }

        $user = DB::transaction(function () use ($validated): User {
            abort_if(User::query()->lockForUpdate()->exists(), 404);

            $claimed = DB::table('installation_states')
                ->where('key', 'initial_setup')
                ->whereNull('completed_at')
                ->update([
                    'completed_at' => now(),
                    'updated_at' => now(),
                ]);
            abort_unless($claimed === 1, 404);

            $user = new User([
                'name' => $validated['name'],
                'email' => Str::lower($validated['email']),
                'password' => $validated['password'],
            ]);
            $user->is_admin = true;
            $user->is_active = true;
            $user->email_verified_at = now();
            $user->save();

            return $user;
        }, 5);
        $issued = $tokens->issue($user, $validated['device']);

        return response()->json([
            ...$tokens->responsePayload(
                $issued['session'],
                $issued['accessToken'],
                $issued['refreshToken'],
            ),
            'user' => $this->userPayload($user),
            'profiles' => [$this->profilePayload($user)],
            'activeProfile' => (string) $user->getKey(),
        ], 201, ['Cache-Control' => 'no-store']);
    }

    /**
     * @return array<string, mixed>
     */
    private function validateLogin(Request $request): array
    {
        return $request->validate([
            'email' => ['required', 'string', 'email', 'max:255'],
            'password' => ['required', 'string', 'max:255'],
            ...$this->deviceRules(),
        ]);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    private function deviceRules(): array
    {
        return [
            'device' => ['required', 'array'],
            'device.installationId' => [
                'required',
                'string',
                'min:16',
                'max:128',
                'regex:/^[A-Za-z0-9._:-]+$/',
            ],
            'device.deviceName' => ['required', 'string', 'max:100'],
            'device.platform' => ['required', 'string', 'in:tvOS'],
            'device.appVersion' => ['required', 'string', 'max:32'],
            'device.osVersion' => ['nullable', 'string', 'max:32'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function userPayload(User $user): array
    {
        return [
            'id' => (string) $user->getKey(),
            'name' => $user->name,
            'email' => $user->email,
            'timezone' => $user->timezone,
            'isAdmin' => $user->isAdmin(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function profilePayload(User $user): array
    {
        return [
            ...$this->userPayload($user),
            'active' => true,
        ];
    }

    private function clientSession(Request $request): NativeClientSession
    {
        /** @var NativeClientSession $session */
        $session = $request->attributes->get('nativeClientSession');

        return $session;
    }
}
