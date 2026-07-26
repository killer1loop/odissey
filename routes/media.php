<?php

use App\Http\Controllers\Media\Admin\IntegrationController;
use App\Http\Controllers\Media\Admin\MediaSourceController;
use App\Http\Controllers\Media\CaptionFetchController;
use App\Http\Controllers\Media\DirectMediaController;
use App\Http\Controllers\Media\ExternalSubtitleController;
use App\Http\Controllers\Media\HlsManifestController;
use App\Http\Controllers\Media\HlsSegmentController;
use App\Http\Controllers\Media\MediaArtworkController;
use App\Http\Controllers\Media\MediaFavoriteController;
use App\Http\Controllers\Media\MediaHistoryController;
use App\Http\Controllers\Media\MediaIndexController;
use App\Http\Controllers\Media\MediaPlayerController;
use App\Http\Controllers\Media\PlaybackProgressController;
use App\Http\Controllers\Media\SubtitleController;
use App\Http\Controllers\Media\TranscodeController;
use App\Http\Controllers\Media\TranscodeStatusController;
use App\Http\Middleware\EnsureUserIsActive;
use App\Http\Middleware\EnsureUserIsAdmin;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', EnsureUserIsActive::class])
    ->prefix('media')
    ->name('media.')
    ->group(function (): void {
        Route::get('/', MediaIndexController::class)->name('index');
        Route::get('/history', MediaHistoryController::class)->name('history');
        Route::middleware(EnsureUserIsAdmin::class)
            ->prefix('admin')->name('admin.')->group(function (): void {
                Route::get('/sources', [MediaSourceController::class, 'index'])->name('sources.index');
                Route::get('/sources/create', [MediaSourceController::class, 'create'])->name('sources.create');
                Route::post('/sources', [MediaSourceController::class, 'store'])->name('sources.store');
                Route::post('/sources/{source}/scan', [MediaSourceController::class, 'scan'])->name('sources.scan');
                Route::delete('/sources/{source}', [MediaSourceController::class, 'destroy'])->name('sources.destroy');
                Route::get('/integrations', [IntegrationController::class, 'edit'])->name('integrations.edit');
                Route::put('/integrations', [IntegrationController::class, 'update'])->name('integrations.update');
            });
        Route::get('/{media}/artwork/{kind}', MediaArtworkController::class)->name('artwork');
        Route::post('/{media}/favorite', [MediaFavoriteController::class, 'store'])->name('favorites.store');
        Route::delete('/{media}/favorite', [MediaFavoriteController::class, 'destroy'])->name('favorites.destroy');
        Route::get('/{media}', MediaPlayerController::class)->name('show');
        Route::get('/{media}/file', DirectMediaController::class)->name('direct');
        Route::put('/{media}/progress', PlaybackProgressController::class)
            ->middleware('throttle:30,1')
            ->name('progress');
        Route::get('/{media}/subtitles/{track}.vtt', SubtitleController::class)
            ->middleware('throttle:6,1')
            ->whereNumber('track')
            ->name('subtitles');
        Route::post('/{media}/captions/fetch', CaptionFetchController::class)->middleware('throttle:10,1')->name('captions.fetch');
        Route::get('/{media}/captions/{subtitle}.vtt', ExternalSubtitleController::class)->name('captions.show');
        Route::post('/{media}/transcodes', TranscodeController::class)
            ->middleware('throttle:10,1')
            ->name('transcodes.store');
        Route::get('/{media}/transcodes/{session}/status', TranscodeStatusController::class)
            ->name('transcodes.status');
        Route::get('/{media}/transcodes/{session}/index.m3u8', HlsManifestController::class)
            ->name('transcodes.manifest');
        Route::get('/{media}/transcodes/{session}/{segment}', HlsSegmentController::class)
            ->where('segment', 'segment-\d{5}\.ts')
            ->name('transcodes.segment');

    });
