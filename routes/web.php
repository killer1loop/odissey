<?php

use App\Http\Controllers\Api\V1\DiscoveryController;
use App\Http\Controllers\FoundationStatusController;
use App\Http\Controllers\HomeController;
use App\Http\Middleware\EnsureUserIsActive;
use App\Http\Middleware\NativeApiHeaders;
use Illuminate\Support\Facades\Route;

Route::get('/', HomeController::class)->name('home');
Route::get('/.well-known/odissey', DiscoveryController::class)
    ->middleware(NativeApiHeaders::class)
    ->name('native.discovery');
Route::get('/foundation-status', FoundationStatusController::class)
    ->middleware(['auth', EnsureUserIsActive::class])
    ->name('foundation-status');

require __DIR__.'/auth.php';
require __DIR__.'/iptv.php';
require __DIR__.'/media.php';
