<?php

use App\Http\Controllers\Api\V1\AdminController;
use App\Http\Controllers\Api\V1\AdminManagementController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\CatalogController;
use App\Http\Controllers\Api\V1\DiscoveryController;
use App\Http\Controllers\Api\V1\LiveTvController;
use App\Http\Controllers\Api\V1\MusicPlaylistController;
use App\Http\Controllers\Api\V1\NativeHlsMasterController;
use App\Http\Controllers\Api\V1\PlaybackController;
use App\Http\Controllers\Api\V1\ProfileController;
use App\Http\Controllers\Iptv\ChannelIconController;
use App\Http\Controllers\Iptv\PlaybackManifestController;
use App\Http\Controllers\Iptv\PlaybackResourceController;
use App\Http\Controllers\Media\DirectMediaController;
use App\Http\Controllers\Media\ExternalSubtitleController;
use App\Http\Controllers\Media\HlsManifestController;
use App\Http\Controllers\Media\HlsSegmentController;
use App\Http\Controllers\Media\MediaArtworkController;
use App\Http\Controllers\Media\SubtitleController;
use App\Http\Middleware\AuthenticateNativeClient;
use App\Http\Middleware\AuthenticateNativePlaybackGrant;
use App\Http\Middleware\NativeApiHeaders;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')
    ->name('api.v1.')
    ->middleware(NativeApiHeaders::class)
    ->group(function (): void {
        Route::get('/server', DiscoveryController::class)->name('server');
        Route::post('/setup/admin', [AuthController::class, 'setup'])
            ->middleware('throttle:5,1')
            ->name('setup.admin');
        Route::post('/auth/login', [AuthController::class, 'login'])
            ->name('auth.login');
        Route::post('/auth/refresh', [AuthController::class, 'refresh'])
            ->middleware('throttle:30,1')
            ->name('auth.refresh');

        Route::middleware(AuthenticateNativeClient::class)
            ->group(function (): void {
                Route::post('/auth/logout', [AuthController::class, 'logout'])
                    ->name('auth.logout');
                Route::get('/auth/sessions', [AuthController::class, 'sessions'])
                    ->name('auth.sessions');
                Route::delete(
                    '/auth/sessions/{session}',
                    [AuthController::class, 'revokeSession'],
                )->name('auth.sessions.destroy');
                Route::get('/me', [ProfileController::class, 'me'])
                    ->name('me');
                Route::put(
                    '/me/preferences',
                    [ProfileController::class, 'updatePreferences'],
                )->name('me.preferences');
                Route::get('/profiles', [ProfileController::class, 'profiles'])
                    ->name('profiles');
                Route::post(
                    '/profiles/{profile}/activate',
                    [ProfileController::class, 'activate'],
                )->name('profiles.activate');

                Route::get('/home', [CatalogController::class, 'home'])
                    ->name('home');
                Route::get('/libraries', [CatalogController::class, 'libraries'])
                    ->name('libraries');
                Route::get(
                    '/libraries/{library}/items',
                    [CatalogController::class, 'libraryItems'],
                )->name('libraries.items');
                Route::get('/media/{media}', [CatalogController::class, 'media'])
                    ->name('media.show');
                Route::get(
                    '/media/{media}/artwork/{kind}',
                    MediaArtworkController::class,
                )->whereIn('kind', ['poster', 'backdrop'])
                    ->middleware('throttle:600,1,media-artwork:')
                    ->name('media.artwork');
                Route::get(
                    '/media/{media}/tracks',
                    [CatalogController::class, 'tracks'],
                )->name('media.tracks');
                Route::get(
                    '/media/{media}/captions',
                    [CatalogController::class, 'captions'],
                )->name('media.captions');
                Route::get(
                    '/media/{media}/captions/{subtitle}.vtt',
                    ExternalSubtitleController::class,
                )->name('media.captions.show');
                Route::get(
                    '/series/{series}/seasons',
                    [CatalogController::class, 'seasons'],
                )->name('series.seasons');
                Route::get(
                    '/seasons/{season}/episodes',
                    [CatalogController::class, 'episodes'],
                )->name('seasons.episodes');
                Route::get('/search', [CatalogController::class, 'search'])
                    ->name('search');
                Route::get('/favorites', [CatalogController::class, 'favorites'])
                    ->name('favorites');
                Route::put(
                    '/favorites/{kind}/{id}',
                    [CatalogController::class, 'storeFavorite'],
                )->name('favorites.store');
                Route::delete(
                    '/favorites/{kind}/{id}',
                    [CatalogController::class, 'destroyFavorite'],
                )->name('favorites.destroy');
                Route::get('/history', [CatalogController::class, 'history'])
                    ->name('history');
                Route::get(
                    '/music/artists',
                    [CatalogController::class, 'musicArtists'],
                )->name('music.artists');
                Route::get(
                    '/music/albums',
                    [CatalogController::class, 'musicAlbums'],
                )->name('music.albums');
                Route::get(
                    '/music/tracks',
                    [CatalogController::class, 'musicTracks'],
                )->name('music.tracks');
                Route::get(
                    '/music/playlists',
                    [MusicPlaylistController::class, 'index'],
                )->name('music.playlists');
                Route::post(
                    '/music/playlists',
                    [MusicPlaylistController::class, 'store'],
                )->middleware(
                    'throttle:30,1,native-playlist-mutation:',
                )->name('music.playlists.store');
                Route::get(
                    '/music/playlists/{playlist}',
                    [MusicPlaylistController::class, 'show'],
                )->name('music.playlists.show');
                Route::put(
                    '/music/playlists/{playlist}',
                    [MusicPlaylistController::class, 'update'],
                )->middleware(
                    'throttle:30,1,native-playlist-mutation:',
                )->name('music.playlists.update');
                Route::delete(
                    '/music/playlists/{playlist}',
                    [MusicPlaylistController::class, 'destroy'],
                )->middleware(
                    'throttle:30,1,native-playlist-mutation:',
                )->name('music.playlists.destroy');
                Route::put(
                    '/media/{media}/progress',
                    [CatalogController::class, 'progress'],
                )->middleware('throttle:60,1')
                    ->name('media.progress');
                Route::post(
                    '/media/{media}/watched',
                    [CatalogController::class, 'watched'],
                )->name('media.watched');

                Route::get('/live/groups', [LiveTvController::class, 'groups'])
                    ->name('live.groups');
                Route::get('/live/channels', [LiveTvController::class, 'channels'])
                    ->name('live.channels');
                Route::get(
                    '/live/channels/{channel}',
                    [LiveTvController::class, 'channel'],
                )->name('live.channels.show');
                Route::get(
                    '/live/channels/{channel}/icon',
                    ChannelIconController::class,
                )->name('live.channels.icon');
                Route::get('/live/guide', [LiveTvController::class, 'guide'])
                    ->name('live.guide');
                Route::get(
                    '/live/channels/{channel}/schedule',
                    [LiveTvController::class, 'schedule'],
                )->name('live.channels.schedule');
                Route::get(
                    '/live/favorites',
                    [LiveTvController::class, 'favorites'],
                )->name('live.favorites');
                Route::put(
                    '/live/favorites/{channel}',
                    [LiveTvController::class, 'storeFavorite'],
                )->name('live.favorites.store');
                Route::delete(
                    '/live/favorites/{channel}',
                    [LiveTvController::class, 'destroyFavorite'],
                )->name('live.favorites.destroy');
                Route::post(
                    '/live/epg/refresh',
                    [LiveTvController::class, 'refreshGuide'],
                )->middleware('throttle:10,1')
                    ->name('live.epg.refresh');

                Route::post(
                    '/playback/resolve',
                    [PlaybackController::class, 'resolveMedia'],
                )->middleware('throttle:20,1')
                    ->name('playback.resolve');
                Route::post(
                    '/live/playback/resolve',
                    [PlaybackController::class, 'resolveLive'],
                )->middleware('throttle:30,1')
                    ->name('live.playback.resolve');
                Route::post(
                    '/playback/sessions/{session}/heartbeat',
                    [PlaybackController::class, 'heartbeat'],
                )->middleware('throttle:60,1')
                    ->name('playback.heartbeat');
                Route::post(
                    '/playback/sessions/{session}/stop',
                    [PlaybackController::class, 'stop'],
                )->name('playback.stop');
                Route::get(
                    '/playback/media/{media}/transcodes/{session}/status',
                    [PlaybackController::class, 'transcodeStatus'],
                )->name('playback.media.transcode.status');

                Route::prefix('admin')
                    ->name('admin.')
                    ->middleware('native.admin')
                    ->group(
                        function (): void {
                            Route::get('/users', [AdminController::class, 'users'])
                                ->name('users');
                            Route::post(
                                '/users',
                                [AdminManagementController::class, 'createUser'],
                            )->name('users.store');
                            Route::patch(
                                '/users/{user}',
                                [AdminManagementController::class, 'updateUser'],
                            )->name('users.update');
                            Route::post(
                                '/users/{user}/disable',
                                [AdminManagementController::class, 'disableUser'],
                            )->name('users.disable');
                            Route::get(
                                '/iptv-providers',
                                [AdminController::class, 'providers'],
                            )->name('iptv-providers');
                            Route::post(
                                '/iptv-providers',
                                [
                                    AdminManagementController::class,
                                    'createProvider',
                                ],
                            )->name('iptv-providers.store');
                            Route::put(
                                '/iptv-providers/{provider}',
                                [
                                    AdminManagementController::class,
                                    'updateProvider',
                                ],
                            )->name('iptv-providers.update');
                            Route::post(
                                '/iptv-providers/{provider}/test',
                                [
                                    AdminManagementController::class,
                                    'testProvider',
                                ],
                            )->name('iptv-providers.test');
                            Route::post(
                                '/iptv-providers/{provider}/sync',
                                [
                                    AdminManagementController::class,
                                    'syncProvider',
                                ],
                            )->name('iptv-providers.sync');
                            Route::post(
                                '/iptv-providers/{provider}/epg',
                                [
                                    AdminManagementController::class,
                                    'syncProviderGuide',
                                ],
                            )->name('iptv-providers.epg');
                            Route::delete(
                                '/iptv-providers/{provider}',
                                [
                                    AdminManagementController::class,
                                    'deleteProvider',
                                ],
                            )->name('iptv-providers.destroy');
                            Route::get(
                                '/media-sources',
                                [AdminController::class, 'mediaSources'],
                            )->name('media-sources');
                            Route::post(
                                '/media-sources',
                                [
                                    AdminManagementController::class,
                                    'createMediaSource',
                                ],
                            )->name('media-sources.store');
                            Route::put(
                                '/media-sources/{source}',
                                [
                                    AdminManagementController::class,
                                    'updateMediaSource',
                                ],
                            )->name('media-sources.update');
                            Route::post(
                                '/media-sources/{source}/test',
                                [
                                    AdminManagementController::class,
                                    'testMediaSource',
                                ],
                            )->name('media-sources.test');
                            Route::post(
                                '/media-sources/{source}/scan',
                                [
                                    AdminManagementController::class,
                                    'scanMediaSource',
                                ],
                            )->name('media-sources.scan');
                            Route::delete(
                                '/media-sources/{source}',
                                [
                                    AdminManagementController::class,
                                    'deleteMediaSource',
                                ],
                            )->name('media-sources.destroy');
                            Route::get(
                                '/integrations',
                                [
                                    AdminManagementController::class,
                                    'integrations',
                                ],
                            )->name('integrations');
                            Route::put(
                                '/integrations',
                                [
                                    AdminManagementController::class,
                                    'updateIntegrations',
                                ],
                            )->name('integrations.update');
                            Route::post(
                                '/media/{media}/metadata/refresh',
                                [
                                    AdminManagementController::class,
                                    'refreshMetadata',
                                ],
                            )->name('media.metadata.refresh');
                            Route::post(
                                '/media/{media}/captions/refresh',
                                [
                                    AdminManagementController::class,
                                    'refreshCaptions',
                                ],
                            )->name('media.captions.refresh');
                            Route::get('/jobs', [AdminController::class, 'jobs'])
                                ->name('jobs');
                            Route::get('/system', [AdminController::class, 'system'])
                                ->name('system');
                        },
                    );
            });

        Route::prefix(
            '/playback/assets/{grant}/{grantToken}',
        )
            ->middleware(AuthenticateNativePlaybackGrant::class)
            ->group(function (): void {
                Route::match(
                    ['GET', 'HEAD'],
                    '/media/{media}/file',
                    DirectMediaController::class,
                )->name('playback.media.direct');
                Route::get(
                    '/media/{media}/subtitles/{track}.vtt',
                    SubtitleController::class,
                )->whereNumber('track')
                    ->middleware('throttle:12,1')
                    ->name('playback.media.subtitles.embedded');
                Route::get(
                    '/media/{media}/captions/{subtitle}.vtt',
                    ExternalSubtitleController::class,
                )->name('playback.media.subtitles.caption');
                Route::get(
                    '/media/{media}/transcodes/{session}/master.m3u8',
                    NativeHlsMasterController::class,
                )->name('playback.media.transcode.master');
                Route::get(
                    '/media/{media}/transcodes/{session}/index.m3u8',
                    HlsManifestController::class,
                )->name('playback.media.transcode.manifest');
                Route::get(
                    '/media/{media}/transcodes/{session}/{segment}',
                    HlsSegmentController::class,
                )->where(
                    'segment',
                    '(?:segment-\d{5}\.(?:ts|m4s)|init\.mp4)',
                )
                    ->name('playback.media.transcode.segment');
                Route::get(
                    '/live/{session}/master.m3u8',
                    PlaybackManifestController::class,
                )->name('playback.live.manifest');
                Route::get(
                    '/live/{session}/resources/{resource}',
                    PlaybackResourceController::class,
                )->scopeBindings()
                    ->name('playback.live.resource');
            });
    });
