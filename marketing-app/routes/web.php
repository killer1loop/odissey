<?php

use App\Http\Controllers\LaunchSignupController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('index');
})->name('home');

Route::get('/health', fn () => response('ok', 200));

Route::post('/subscribe', LaunchSignupController::class)
    ->middleware('throttle:10,1')
    ->name('subscribe');
