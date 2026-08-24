<?php

namespace App\Http\Controllers;

use App\Models\LaunchSignup;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class LaunchSignupController
{
    public function __invoke(Request $request): Response
    {
        $validator = Validator::make($request->only('email'), [
            'email' => ['required', 'string', 'email:rfc', 'max:255'],
        ]);

        if ($validator->fails()) {
            return response()
                ->view('partials.subscribe', [
                    'email' => $request->string('email')->toString(),
                    'validationErrors' => $validator->errors(),
                ], 422)
                ->header('Cache-Control', 'no-store');
        }

        LaunchSignup::query()->firstOrCreate([
            'email' => Str::lower(trim($validator->validated()['email'])),
        ]);

        return response()
            ->view('partials.subscribed')
            ->header('Cache-Control', 'no-store');
    }
}
