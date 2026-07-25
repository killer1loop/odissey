<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class HomeController extends Controller
{
    public function __invoke(Request $request): View|RedirectResponse
    {
        if (User::query()->doesntExist()) {
            return redirect()->route('setup.create');
        }

        if ($request->user() === null) {
            return redirect()->route('login');
        }

        if (! $request->user()->isActive()) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()
                ->route('login')
                ->withErrors(['email' => 'This account has been disabled.']);
        }

        return view('home');
    }
}
