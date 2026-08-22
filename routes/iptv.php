<?php

use App\Http\Controllers\Iptv\Admin\IptvProviderController;
use App\Http\Controllers\Iptv\ChannelBrowserController;
use App\Http\Controllers\Iptv\ChannelFavoriteController;
use App\Http\Controllers\Iptv\ChannelIconController;
use App\Http\Controllers\Iptv\GuideController;
use App\Http\Controllers\Iptv\PlaybackDiagnosticController;
use App\Http\Controllers\Iptv\PlaybackManifestController;
use App\Http\Controllers\Iptv\PlaybackResourceController;
use App\Http\Controllers\Iptv\PlaybackRestartController;
use App\Http\Controllers\Iptv\PlaybackSessionController;
use App\Http\Middleware\EnsureUserIsActive;
use App\Http\Middleware\EnsureUserIsAdmin;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', EnsureUserIsActive::class])
    ->prefix('iptv')
    ->name('iptv.')
    ->group(function (): void {
        Route::get('/', ChannelBrowserController::class)->name('channels.index');
        Route::get('/guide', GuideController::class)->name('guide');
        Route::get('/channels/{channel}/icon', ChannelIconController::class)
            ->name('channels.icon')
            ->middleware('throttle:120,1,channel-icon:');

        Route::post('/channels/{channel}/favorite', [ChannelFavoriteController::class, 'store'])
            ->name('favorites.store');
        Route::delete('/channels/{channel}/favorite', [ChannelFavoriteController::class, 'destroy'])
            ->name('favorites.destroy');

        Route::post('/channels/{channel}/play', [PlaybackSessionController::class, 'store'])
            ->middleware('throttle:30,1')
            ->name('playback.store');
        Route::get('/play/{session}', [PlaybackSessionController::class, 'show'])
            ->name('playback.show');
        Route::delete('/play/{session}', [PlaybackSessionController::class, 'destroy'])
            ->name('playback.destroy');
        Route::get('/play/{session}/master.m3u8', PlaybackManifestController::class)
            ->name('playback.manifest');
        Route::post('/play/{session}/restart', PlaybackRestartController::class)
            ->middleware('throttle:6,1')
            ->name('playback.restart');
        Route::post(
            '/play/{session}/diagnostics',
            PlaybackDiagnosticController::class,
        )
            ->middleware('throttle:20,1')
            ->name('playback.diagnostics');
        Route::get(
            '/play/{session}/resources/{resource}',
            PlaybackResourceController::class,
        )
            ->scopeBindings()
            ->name('playback.resource');

        Route::middleware(EnsureUserIsAdmin::class)
            ->prefix('admin')
            ->name('admin.')
            ->group(function (): void {
                Route::get('/providers', [IptvProviderController::class, 'index'])
                    ->name('providers.index');
                Route::get('/providers/create', [IptvProviderController::class, 'create'])
                    ->name('providers.create');
                Route::post('/providers', [IptvProviderController::class, 'store'])
                    ->name('providers.store');
                Route::get('/providers/{provider}/edit', [IptvProviderController::class, 'edit'])
                    ->name('providers.edit');
                Route::put('/providers/{provider}', [IptvProviderController::class, 'update'])
                    ->name('providers.update');
                Route::delete('/providers/{provider}', [IptvProviderController::class, 'destroy'])
                    ->name('providers.destroy');
                Route::post('/providers/{provider}/sync', [IptvProviderController::class, 'sync'])
                    ->middleware('throttle:10,1')
                    ->name('providers.sync');
                Route::post('/providers/{provider}/guide', [IptvProviderController::class, 'syncGuide'])
                    ->middleware('throttle:10,1')
                    ->name('providers.guide');
            });
    });
