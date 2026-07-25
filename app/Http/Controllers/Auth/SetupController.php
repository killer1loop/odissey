<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\SetupRequest;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class SetupController extends Controller
{
    public function create(): View
    {
        return view('auth.setup', [
            'requiresSetupToken' => $this->requiresSetupToken(),
        ]);
    }

    public function store(SetupRequest $request): RedirectResponse
    {
        if (! $this->hasValidSetupToken($request)) {
            return back()
                ->withErrors(['setup_token' => 'The setup token is invalid.'])
                ->withInput($request->safe()->only(['name', 'email']));
        }

        /** @var array{name: string, email: string, password: string} $attributes */
        $attributes = $request->safe()->only(['name', 'email', 'password']);

        $user = DB::transaction(function () use ($attributes): User {
            abort_if(User::query()->exists(), 404);

            $claimed = DB::table('installation_states')
                ->where('key', 'initial_setup')
                ->whereNull('completed_at')
                ->update([
                    'completed_at' => now(),
                    'updated_at' => now(),
                ]);

            abort_unless($claimed === 1, 404);

            $user = new User($attributes);
            $user->is_admin = true;
            $user->is_active = true;
            $user->email_verified_at = now();
            $user->save();

            return $user;
        }, attempts: 5);

        Auth::login($user);
        $request->session()->regenerate();

        return redirect()
            ->route('home')
            ->with('status', 'Administrator account created.');
    }

    private function requiresSetupToken(): bool
    {
        return app()->environment('production');
    }

    private function hasValidSetupToken(SetupRequest $request): bool
    {
        if (! $this->requiresSetupToken()) {
            return true;
        }

        $configuredToken = (string) config('odissey-auth.setup_token');
        $providedToken = (string) $request->input('setup_token');

        return $configuredToken !== ''
            && $providedToken !== ''
            && hash_equals($configuredToken, $providedToken);
    }
}
