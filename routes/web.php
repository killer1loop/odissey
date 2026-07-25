<?php

use App\Http\Controllers\FoundationStatusController;
use App\Http\Controllers\HomeController;
use Illuminate\Support\Facades\Route;

Route::get('/', HomeController::class)->name('home');
Route::get('/foundation-status', FoundationStatusController::class)->name('foundation-status');
