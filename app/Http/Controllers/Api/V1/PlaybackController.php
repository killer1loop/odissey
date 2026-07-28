<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Jobs\Media\TranscodeMediaToHls;
use App\Models\Iptv\Channel;
use App\Models\Iptv\IptvPlaybackSession;
use App\Models\MediaItem;
use App\Models\MediaSubtitle;
use App\Models\NativeClientSession;
use App\Models\NativePlaybackGrant;
use App\Models\PlaybackHistory;
use App\Models\TranscodeSession;
use App\Services\Api\PlaybackGrantService;
use App\Services\Iptv\PlaybackSessionManager;
use App\Services\Media\PlaybackDecision;
use App\Services\Media\TranscodeStorage;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PlaybackController extends Controller
{
    public function resolveMedia(
        Request $request,
        PlaybackDecision $decisions,
        PlaybackGrantService $grants,
        TranscodeStorage $storage,
    ): JsonResponse {
        $validated = $this->validateResolution($request, mediaRequired: true);
        $item = MediaItem::query()
            ->accessibleTo($request->user())
            ->with('subtitles')
            ->findOrFail($validated['mediaId']);
        abort_if(($item->metadata['kind'] ?? null) === 'series', 409);

        $capabilities = $validated['device'];
        $capabilities['videoCodecs'] = array_values(array_intersect(
            $capabilities['videoCodecs'],
            ['h264', 'hevc'],
        ));
        $audioTrack = $this->selectedAudioTrack(
            $item,
            $validated['audioTrackId'] ?? null,
        );
        [$subtitleTrack, $mediaSubtitle, $subtitleTrackId]
            = $this->selectedSubtitle(
                $item,
                $validated['subtitleTrackId'] ?? null,
            );
        $preferences = $validated['preferences'] ?? [];
        $preferences['selectedSubtitle'] = $subtitleTrackId !== null;
        $preferences['selectedAudio'] = $audioTrack !== null;
        if ($audioTrack !== null) {
            $preferences['selectedAudioCodec'] = (string) (
                $item->metadata['technical']['audio_tracks'][$audioTrack]['codec']
                ?? ''
            );
        }
        $decision = $decisions->forNative(
            $item,
            $capabilities,
            $preferences,
        );
        $transcode = null;
        if ($decision['mode'] !== 'direct') {
            abort_if(
                ($validated['preferences']['allowTranscode'] ?? true) !== true,
                409,
                'The media is incompatible and transcoding is disabled.',
            );
            $transcode = $this->transcodeSession(
                $request,
                $item,
                $storage,
                $decision['mode'],
                $audioTrack,
                $subtitleTrack,
                $mediaSubtitle,
            );
        }

        $issued = $grants->issue(
            $this->clientSession($request),
            'media',
            (string) $item->getKey(),
            $decision['mode'],
            $transcode === null ? null : (string) $transcode->getKey(),
        );
        $grant = $issued['grant'];
        $grantToken = $issued['token'];
        $url = $decision['mode'] === 'direct'
            ? route('api.v1.playback.media.direct', [
                $grant,
                $grantToken,
                $item,
            ])
            : route(
                $subtitleTrackId === null
                    ? 'api.v1.playback.media.transcode.manifest'
                    : 'api.v1.playback.media.transcode.master',
                [
                    $grant,
                    $grantToken,
                    $item,
                    $transcode,
                ],
            );
        $this->recordStarted($request, $item, $validated['positionMs'] ?? 0);

        return response()->json([
            'sessionId' => (string) $grant->getKey(),
            'mode' => $decision['mode'],
            'decisionReason' => $decision['reason'],
            'status' => $transcode?->status ?? 'ready',
            'url' => $url,
            'statusUrl' => $transcode === null ? null : route(
                'api.v1.playback.media.transcode.status',
                [$item, $transcode],
            ),
            'expiresAt' => $grant->expires_at->utc()->toIso8601String(),
            'container' => $decision['container'],
            'video' => [
                'codec' => $item->video_codec,
                'width' => $this->technicalInteger($item, 'width'),
                'height' => $this->technicalInteger($item, 'height'),
                'frameRate' => $this->technicalFloat($item, 'frame_rate'),
                'dynamicRange' => $this->technicalString(
                    $item,
                    'dynamic_range',
                ),
            ],
            'audio' => [
                'codec' => $item->audio_codec,
                'channels' => $this->technicalInteger(
                    $item,
                    'audio_channels',
                ),
                'atmos' => (bool) (
                    $item->metadata['technical']['atmos'] ?? false
                ),
            ],
            'availableAudioTracks' => $this->audioTracks($item),
            'availableSubtitleTracks' => $this->subtitleTracks(
                $item,
                $grant,
                $grantToken,
            ),
            'selectedAudioTrackId' => $audioTrack === null
                ? null
                : (string) $audioTrack,
            'selectedSubtitleTrackId' => $subtitleTrackId,
            'heartbeatIntervalSeconds' => 20,
        ], headers: ['Cache-Control' => 'no-store']);
    }

    public function resolveLive(
        Request $request,
        PlaybackSessionManager $sessions,
        PlaybackGrantService $grants,
    ): JsonResponse {
        $validated = $request->validate([
            'channelId' => ['required', 'integer', 'min:1'],
            'device' => ['required', 'array'],
            'device.platform' => ['required', 'in:tvOS'],
            'device.osVersion' => ['required', 'string', 'max:32'],
        ]);
        $channel = $this->availableChannel()
            ->with(['provider', 'group'])
            ->findOrFail($validated['channelId']);
        $playbackSession = $sessions->create($request->user(), $channel);
        $issued = $grants->issue(
            $this->clientSession($request),
            'channel',
            (string) $channel->getKey(),
            'direct',
            (string) $playbackSession->getKey(),
        );
        $grant = $issued['grant'];

        return response()->json([
            'sessionId' => (string) $grant->getKey(),
            'mode' => 'direct',
            'decisionReason' => 'authenticated-provider-hls-gateway',
            'status' => 'ready',
            'url' => route('api.v1.playback.live.manifest', [
                $grant,
                $issued['token'],
                $playbackSession,
            ]),
            'expiresAt' => $grant->expires_at->utc()->toIso8601String(),
            'container' => 'hls',
            'channelId' => (string) $channel->getKey(),
            'heartbeatIntervalSeconds' => 20,
        ], headers: ['Cache-Control' => 'no-store']);
    }

    public function heartbeat(
        Request $request,
        PlaybackGrantService $grants,
        string $session,
    ): JsonResponse {
        $clientSession = $this->clientSession($request);
        $grant = DB::transaction(function () use (
            $clientSession,
            $grants,
            $request,
            $session,
        ): NativePlaybackGrant {
            $grant = NativePlaybackGrant::query()
                ->where('native_client_session_id', $clientSession->id)
                ->where('user_id', $request->user()->id)
                ->whereNull('revoked_at')
                ->where('expires_at', '>', now())
                ->lockForUpdate()
                ->findOrFail($session);
            $renewed = now()->addMinutes(
                $grants->renewalMinutes(),
            );
            if ($renewed->gt($clientSession->refresh_expires_at)) {
                $renewed = $clientSession->refresh_expires_at;
            }
            $grant->forceFill([
                'last_used_at' => now(),
                'expires_at' => $renewed,
            ])->save();

            return $grant;
        }, 3);

        return response()->json([
            'accepted' => true,
            'expiresAt' => $grant->expires_at->utc()->toIso8601String(),
        ], headers: ['Cache-Control' => 'no-store']);
    }

    public function stop(
        Request $request,
        PlaybackSessionManager $sessions,
        string $session,
    ): JsonResponse {
        $grant = NativePlaybackGrant::query()
            ->where('native_client_session_id', $this->clientSession($request)->id)
            ->where('user_id', $request->user()->id)
            ->findOrFail($session);
        if (
            $grant->resource_type === 'channel'
            && is_string($grant->playback_reference)
        ) {
            $anotherGrantUsesPlayback = NativePlaybackGrant::query()
                ->where('user_id', $grant->user_id)
                ->where('resource_type', $grant->resource_type)
                ->where('resource_id', $grant->resource_id)
                ->where('playback_reference', $grant->playback_reference)
                ->where('id', '!=', $grant->getKey())
                ->whereNull('revoked_at')
                ->where('expires_at', '>', now())
                ->exists();
            if (! $anotherGrantUsesPlayback) {
                $playback = IptvPlaybackSession::query()
                    ->where('user_id', $request->user()->id)
                    ->find($grant->playback_reference);
                if ($playback !== null) {
                    $sessions->release($playback);
                }
            }
        }
        $now = now();
        $grant->forceFill([
            'revoked_at' => $now,
            'expires_at' => $now,
        ])->save();

        return response()->json(['stopped' => true], headers: [
            'Cache-Control' => 'no-store',
        ]);
    }

    public function transcodeStatus(
        Request $request,
        string $media,
        string $session,
    ): JsonResponse {
        $item = MediaItem::query()
            ->accessibleTo($request->user())
            ->findOrFail($media);
        $transcode = TranscodeSession::query()
            ->whereBelongsTo($request->user())
            ->whereBelongsTo($item, 'mediaItem')
            ->findOrFail($session);

        return response()->json([
            'status' => $transcode->status,
            'errorCode' => $transcode->error_code,
            'startedAt' => $transcode->started_at?->utc()->toIso8601String(),
            'finishedAt' => $transcode->finished_at?->utc()->toIso8601String(),
            'expiresAt' => $transcode->expires_at?->utc()->toIso8601String(),
        ], headers: ['Cache-Control' => 'no-store']);
    }

    /**
     * @return array<string, mixed>
     */
    private function validateResolution(
        Request $request,
        bool $mediaRequired,
    ): array {
        return $request->validate([
            'mediaId' => [
                $mediaRequired ? 'required' : 'nullable',
                'string',
                'max:64',
            ],
            'intent' => ['nullable', 'in:play,resume'],
            'positionMs' => [
                'nullable',
                'integer',
                'min:0',
                'max:604800000',
            ],
            'device' => ['required', 'array'],
            'device.platform' => ['required', 'in:tvOS'],
            'device.osVersion' => ['required', 'string', 'max:32'],
            'device.modelFamily' => ['required', 'in:AppleTV4K'],
            'device.maximumWidth' => ['required', 'integer', 'min:640', 'max:7680'],
            'device.maximumHeight' => ['required', 'integer', 'min:360', 'max:4320'],
            'device.maximumFrameRate' => ['required', 'integer', 'min:24', 'max:120'],
            'device.dynamicRanges' => ['required', 'array', 'max:8'],
            'device.dynamicRanges.*' => [
                'string',
                'in:sdr,hdr10,hdr10Plus,hlg,dolbyVision',
            ],
            'device.videoCodecs' => ['required', 'array', 'max:8'],
            'device.videoCodecs.*' => ['string', 'in:h264,hevc,av1'],
            'device.audioCodecs' => ['required', 'array', 'max:16'],
            'device.audioCodecs.*' => [
                'string',
                'in:aac,ac3,eac3,alac,flac,mp3,opus',
            ],
            'device.subtitleFormats' => ['required', 'array', 'max:8'],
            'device.subtitleFormats.*' => ['string', 'in:webvtt,imsc1'],
            'preferences' => ['nullable', 'array'],
            'preferences.maximumBitrate' => [
                'nullable',
                'integer',
                'min:64000',
                'max:200000000',
            ],
            'preferences.preferOriginalQuality' => ['nullable', 'boolean'],
            'preferences.allowTranscode' => ['nullable', 'boolean'],
            'audioTrackId' => [
                'nullable',
                'string',
                'regex:/^(?:embedded:)?(?:[0-9]|[12][0-9]|3[01])$/',
            ],
            'subtitleTrackId' => [
                'nullable',
                'string',
                'regex:/^(?:embedded:(?:[0-9]|[12][0-9]|3[01])|caption:[0-9A-HJKMNP-TV-Z]{26})$/i',
            ],
        ]);
    }

    private function transcodeSession(
        Request $request,
        MediaItem $item,
        TranscodeStorage $storage,
        string $deliveryMode,
        ?int $audioTrack,
        ?int $subtitleTrack,
        ?MediaSubtitle $mediaSubtitle,
    ): TranscodeSession {
        [$session, $created] = DB::transaction(function () use (
            $request,
            $item,
            $storage,
            $deliveryMode,
            $audioTrack,
            $subtitleTrack,
            $mediaSubtitle,
        ): array {
            $existing = TranscodeSession::query()
                ->whereBelongsTo($request->user())
                ->whereBelongsTo($item, 'mediaItem')
                ->where('delivery_mode', $deliveryMode)
                ->where('audio_track', $audioTrack)
                ->where('subtitle_track', $subtitleTrack)
                ->where(
                    'media_subtitle_id',
                    $mediaSubtitle?->getKey(),
                )
                ->whereIn('status', [
                    TranscodeSession::STATUS_PENDING,
                    TranscodeSession::STATUS_PROCESSING,
                    TranscodeSession::STATUS_READY,
                ])
                ->where(function (Builder $query): void {
                    $query
                        ->whereNot('status', TranscodeSession::STATUS_READY)
                        ->orWhereNull('expires_at')
                        ->orWhere('expires_at', '>', now());
                })
                ->lockForUpdate()
                ->latest()
                ->first();
            if (
                $existing?->isAvailable()
                && ! $storage->hasCompleteOutput($existing)
            ) {
                $storage->delete($existing);
                $existing->delete();
                $existing = null;
            }
            if ($existing !== null) {
                return [$existing, false];
            }

            $active = [
                TranscodeSession::STATUS_PENDING,
                TranscodeSession::STATUS_PROCESSING,
            ];
            $perUser = min(
                20,
                max(
                    1,
                    (int) config(
                        'odissey.max_pending_transcodes_per_user',
                        3,
                    ),
                ),
            );
            $global = min(
                500,
                max(
                    $perUser,
                    (int) config('odissey.max_pending_transcodes', 50),
                ),
            );
            abort_if(
                TranscodeSession::query()
                    ->whereBelongsTo($request->user())
                    ->whereIn('status', $active)
                    ->count() >= $perUser,
                429,
                'Your transcode queue is full.',
            );
            abort_if(
                TranscodeSession::query()
                    ->whereIn('status', $active)
                    ->count() >= $global,
                503,
                'The transcode queue is currently full.',
            );

            return [
                TranscodeSession::query()->create([
                    'user_id' => $request->user()->id,
                    'media_item_id' => $item->id,
                    'status' => TranscodeSession::STATUS_PENDING,
                    'profile' => 'auto',
                    'delivery_mode' => $deliveryMode,
                    'audio_track' => $audioTrack,
                    'subtitle_track' => $subtitleTrack,
                    'media_subtitle_id' => $mediaSubtitle?->getKey(),
                ]),
                true,
            ];
        }, 3);

        if ($created) {
            TranscodeMediaToHls::dispatch($session->getKey())->afterCommit();
        }

        return $session;
    }

    private function recordStarted(
        Request $request,
        MediaItem $item,
        int $position,
    ): void {
        $recent = PlaybackHistory::query()
            ->whereBelongsTo($request->user())
            ->whereBelongsTo($item, 'mediaItem')
            ->where('event', 'started')
            ->where('played_at', '>=', now()->subMinutes(5))
            ->exists();
        if (! $recent) {
            PlaybackHistory::query()->create([
                'user_id' => $request->user()->id,
                'media_item_id' => $item->id,
                'event' => 'started',
                'position_ms' => $position,
                'watched_ms' => 0,
                'played_at' => now(),
            ]);
        }
    }

    private function clientSession(Request $request): NativeClientSession
    {
        /** @var NativeClientSession $session */
        $session = $request->attributes->get('nativeClientSession');

        return $session;
    }

    private function availableChannel(): Builder
    {
        return Channel::query()
            ->select('channels.*')
            ->join(
                'iptv_providers',
                'iptv_providers.id',
                '=',
                'channels.iptv_provider_id',
            )
            ->where('channels.is_active', true)
            ->where('iptv_providers.enabled', true)
            ->where(function (Builder $query): void {
                $query->whereNull('channels.channel_group_id')
                    ->orWhereHas(
                        'group',
                        fn (Builder $group) => $group->where(
                            'is_active',
                            true,
                        ),
                    );
            });
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function audioTracks(MediaItem $item): array
    {
        $tracks = $item->metadata['technical']['audio_tracks'] ?? [];
        if (! is_array($tracks)) {
            return [];
        }

        return collect($tracks)->take(32)->values()
            ->map(fn (mixed $track, int $index): array => [
                'id' => (string) $index,
                'codec' => is_array($track)
                    ? (string) ($track['codec'] ?? '')
                    : '',
                'language' => is_array($track)
                    ? (string) ($track['language'] ?? '')
                    : '',
                'label' => is_array($track)
                    ? (string) ($track['title'] ?? '')
                    : '',
            ])->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function subtitleTracks(
        MediaItem $item,
        NativePlaybackGrant $grant,
        string $grantToken,
    ): array {
        $embedded = $item->metadata['technical']['subtitle_tracks'] ?? [];
        $tracks = collect(is_array($embedded) ? $embedded : [])
            ->take(32)
            ->values()
            ->map(fn (mixed $track, int $index): array => [
                'id' => 'embedded:'.$index,
                'codec' => is_array($track)
                    ? (string) ($track['codec'] ?? '')
                    : '',
                'language' => is_array($track)
                    ? (string) ($track['language'] ?? '')
                    : '',
                'label' => is_array($track)
                    ? (string) ($track['title'] ?? '')
                    : '',
                'format' => 'webvtt',
                'url' => route(
                    'api.v1.playback.media.subtitles.embedded',
                    [$grant, $grantToken, $item, $index],
                ),
            ]);
        foreach ($item->subtitles as $subtitle) {
            $tracks->push([
                'id' => 'caption:'.$subtitle->getKey(),
                'codec' => 'webvtt',
                'language' => $subtitle->language,
                'label' => $subtitle->label,
                'hearingImpaired' => (bool) $subtitle->hearing_impaired,
                'format' => 'webvtt',
                'url' => route(
                    'api.v1.playback.media.subtitles.caption',
                    [$grant, $grantToken, $item, $subtitle],
                ),
            ]);
        }

        return $tracks->all();
    }

    private function technicalInteger(
        MediaItem $item,
        string $key,
    ): ?int {
        $value = $item->metadata['technical'][$key] ?? null;

        return is_numeric($value) ? (int) $value : null;
    }

    private function technicalFloat(
        MediaItem $item,
        string $key,
    ): ?float {
        $value = $item->metadata['technical'][$key] ?? null;

        return is_numeric($value) ? (float) $value : null;
    }

    private function technicalString(
        MediaItem $item,
        string $key,
    ): ?string {
        $value = $item->metadata['technical'][$key] ?? null;

        return is_string($value) ? mb_substr($value, 0, 32) : null;
    }

    private function selectedAudioTrack(
        MediaItem $item,
        mixed $trackId,
    ): ?int {
        if ($trackId === null) {
            return null;
        }
        $track = (int) str_replace('embedded:', '', (string) $trackId);
        $tracks = $item->metadata['technical']['audio_tracks'] ?? [];
        abort_unless(
            is_array($tracks)
                && array_key_exists($track, array_values($tracks)),
            422,
            'The selected audio track is unavailable.',
        );

        return $track;
    }

    /**
     * @return array{0: ?int, 1: ?MediaSubtitle, 2: ?string}
     */
    private function selectedSubtitle(
        MediaItem $item,
        mixed $trackId,
    ): array {
        if ($trackId === null) {
            return [null, null, null];
        }
        $trackId = (string) $trackId;
        if (str_starts_with($trackId, 'embedded:')) {
            $track = (int) substr($trackId, 9);
            $tracks = $item->metadata['technical']['subtitle_tracks'] ?? [];
            abort_unless(
                is_array($tracks)
                    && array_key_exists($track, array_values($tracks)),
                422,
                'The selected subtitle track is unavailable.',
            );

            return [$track, null, 'embedded:'.$track];
        }

        $captionId = substr($trackId, 8);
        $caption = $item->subtitles->firstWhere('id', $captionId);
        abort_unless($caption instanceof MediaSubtitle, 422);

        return [null, $caption, 'caption:'.$caption->getKey()];
    }
}
