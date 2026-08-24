<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('index');
})->name('home');

Route::get('/health', fn () => response('ok', 200));

Route::post('/subscribe', function (Request $request) {
    $validated = $request->validate([
        'email' => ['required', 'email:rfc', 'max:255'],
    ]);

    Log::info('Marketing launch signup.', ['email_domain' => substr(strrchr($validated['email'], '@') ?: '', 1)]);

    return response()
        ->view('partials.subscribed')
        ->header('Cache-Control', 'no-store');
})->middleware('throttle:10,1')->name('subscribe');
