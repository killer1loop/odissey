<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreUserRequest;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;

class UserController extends Controller
{
    public function index(): View
    {
        return view('admin.users.index', [
            'users' => User::query()
                ->orderByDesc('is_admin')
                ->orderBy('name')
                ->get(),
        ]);
    }

    public function store(StoreUserRequest $request): RedirectResponse
    {
        /** @var array{name: string, email: string, password: string} $attributes */
        $attributes = $request->safe()->only(['name', 'email', 'password']);

        $user = new User($attributes);
        $user->is_admin = false;
        $user->is_active = true;
        $user->email_verified_at = now();
        $user->save();

        return redirect()
            ->route('admin.users.index')
            ->with('status', "User {$user->name} created.");
    }

    public function disable(User $user): RedirectResponse
    {
        abort_if($user->is_admin, Response::HTTP_FORBIDDEN);

        if ($user->is_active) {
            $user->is_active = false;
            $user->disabled_at = now();
            $user->remember_token = null;
            $user->save();
        }

        return redirect()
            ->route('admin.users.index')
            ->with('status', "User {$user->name} disabled.");
    }
}
