<?php

use App\Http\Controllers\Media\DirectMediaController;
use App\Http\Controllers\Media\HlsManifestController;
use App\Http\Controllers\Media\HlsSegmentController;
use App\Http\Controllers\Media\MediaIndexController;
use App\Http\Controllers\Media\MediaPlayerController;
use App\Http\Controllers\Media\PlaybackProgressController;
use App\Http\Controllers\Media\TranscodeController;
use App\Http\Controllers\Media\TranscodeStatusController;
use App\Http\Middleware\EnsureUserIsActive;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', EnsureUserIsActive::class])
    ->prefix('media')
    ->name('media.')
    ->group(function (): void {
        Route::get('/', MediaIndexController::class)->name('index');
        Route::get('/{media}', MediaPlayerController::class)->name('show');
        Route::get('/{media}/file', DirectMediaController::class)->name('direct');
        Route::put('/{media}/progress', PlaybackProgressController::class)->name('progress');
        Route::post('/{media}/transcodes', TranscodeController::class)->name('transcodes.store');
        Route::get('/{media}/transcodes/{session}/status', TranscodeStatusController::class)
            ->name('transcodes.status');
        Route::get('/{media}/transcodes/{session}/index.m3u8', HlsManifestController::class)
            ->name('transcodes.manifest');
        Route::get('/{media}/transcodes/{session}/{segment}', HlsSegmentController::class)
            ->where('segment', 'segment-\d{5}\.ts')
            ->name('transcodes.segment');
    });
