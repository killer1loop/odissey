<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use DateTimeZone;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class UserPreferencesController extends Controller
{
    public function edit(Request $request): View
    {
        return view('auth.preferences', ['timezones' => DateTimeZone::listIdentifiers()]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate(['timezone' => ['required', Rule::in(DateTimeZone::listIdentifiers())], 'autoplay' => ['boolean'], 'preferred_quality' => ['required', Rule::in(['auto', 'original', '1080p', '720p'])]]);
        $request->user()->update(['timezone' => $data['timezone'], 'preferences' => ['autoplay' => $request->boolean('autoplay'), 'preferred_quality' => $data['preferred_quality']]]);

        return back()->with('status', 'Preferences saved.');
    }
}
