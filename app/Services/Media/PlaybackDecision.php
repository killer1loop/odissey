<?php

namespace App\Services\Media;

use App\Models\MediaItem;

class PlaybackDecision
{
    public function for(MediaItem $item): string
    {
        if (in_array($item->container, ['mp4', 'mov'], true) && $item->video_codec === 'h264' && in_array($item->audio_codec, ['aac', 'mp3'], true)) {
            return 'direct';
        }
        if ($item->video_codec === 'h264' && in_array($item->audio_codec, ['aac', 'mp3'], true)) {
            return 'remux';
        }

        return 'transcode';
    }

    /**
     * Pick the cheapest safe conversion mode for a web transcode session.
     * H.264 video can always be stream-copied into HLS, so only the audio
     * track needs converting when it is the sole incompatibility. Anything
     * else (unknown codecs, HEVC, non-video kinds) falls back to the full
     * conversion path.
     */
    public function deliveryModeFor(MediaItem $item): string
    {
        $videoCodec = strtolower(trim((string) $item->video_codec));
        $audioCodec = strtolower(trim((string) $item->audio_codec));

        if (
            $item->media_kind !== 'music'
            && $videoCodec === 'h264'
            && $audioCodec !== ''
            && ! in_array($audioCodec, ['aac', 'mp3'], true)
        ) {
            return 'audioTranscode';
        }

        return 'fullTranscode';
    }

    /**
     * Make a conservative native-client decision from probed media and a
     * bounded, server-validated capability declaration.
     *
     * @param  array{
     *     videoCodecs?: list<string>,
     *     audioCodecs?: list<string>,
     *     dynamicRanges?: list<string>,
     *     maximumWidth?: int,
     *     maximumHeight?: int,
     *     maximumFrameRate?: int,
     *     subtitleFormats?: list<string>
     * }  $capabilities
     * @param  array{
     *     maximumBitrate?: int,
     *     selectedSubtitle?: bool,
     *     selectedAudio?: bool,
     *     selectedAudioCodec?: string
     * }  $preferences
     * @return array{mode: string, reason: string, container: string}
     */
    public function forNative(
        MediaItem $item,
        array $capabilities,
        array $preferences = [],
    ): array {
        $container = strtolower((string) $item->container);
        $videoCodec = $this->normalizeCodec($item->video_codec);
        $audioCodec = $this->normalizeCodec(
            $preferences['selectedAudioCodec'] ?? $item->audio_codec,
        );
        $technical = is_array($item->metadata['technical'] ?? null)
            ? $item->metadata['technical']
            : [];
        $videoCodecs = array_map(
            $this->normalizeCodec(...),
            $capabilities['videoCodecs'] ?? [],
        );
        $audioCodecs = array_map(
            $this->normalizeCodec(...),
            $capabilities['audioCodecs'] ?? [],
        );
        $isAudioOnly = $item->media_kind === 'music' && $videoCodec === '';
        $videoCompatible = $isAudioOnly
            || (
                in_array($videoCodec, ['h264', 'hevc'], true)
                && in_array($videoCodec, $videoCodecs, true)
            );
        $audioCompatible = $audioCodec === ''
            || in_array($audioCodec, $audioCodecs, true);
        $containerCompatible = in_array(
            $container,
            $isAudioOnly
                ? ['mp3', 'm4a', 'aac', 'flac', 'wav']
                : ['mp4', 'm4v', 'mov'],
            true,
        );

        if (! $isAudioOnly) {
            if (! $videoCompatible) {
                return $this->transcode(
                    $videoCodec === 'av1'
                        ? 'av1-is-not-declared-native-compatible'
                        : 'video-codec-not-supported',
                );
            }

            $width = $this->positiveInteger($technical['width'] ?? null);
            $height = $this->positiveInteger($technical['height'] ?? null);
            if ($width === null || $height === null) {
                return $this->transcode('unknown-video-dimensions');
            }
            if (
                $width > (int) ($capabilities['maximumWidth'] ?? 0)
                || $height > (int) ($capabilities['maximumHeight'] ?? 0)
            ) {
                return $this->transcode('video-dimensions-exceed-device-limit');
            }

            $frameRate = $this->positiveFloat(
                $technical['frame_rate'] ?? null,
            );
            if ($frameRate === null) {
                return $this->transcode('unknown-video-frame-rate');
            }
            if (
                $frameRate
                    > (float) ($capabilities['maximumFrameRate'] ?? 0)
            ) {
                return $this->transcode('video-frame-rate-exceeds-device-limit');
            }

            $bitDepth = $this->positiveInteger(
                $technical['bit_depth'] ?? null,
            );
            if ($bitDepth === null) {
                return $this->transcode('unknown-video-bit-depth');
            }
            if (
                ($videoCodec === 'h264' && $bitDepth > 8)
                || ($videoCodec === 'hevc' && $bitDepth > 10)
            ) {
                return $this->transcode('video-bit-depth-not-supported');
            }

            $profile = $this->normalizeProfile(
                $technical['video_profile'] ?? null,
            );
            if ($profile === '') {
                return $this->transcode('unknown-video-profile');
            }
            $profiles = $videoCodec === 'h264'
                ? ['baseline', 'constrainedbaseline', 'main', 'high']
                : ['main', 'main10'];
            if (! in_array($profile, $profiles, true)) {
                return $this->transcode('video-profile-not-supported');
            }

            $level = $this->normalizedLevel(
                $technical['video_level'] ?? null,
                $videoCodec,
            );
            if ($level === null) {
                return $this->transcode('unknown-video-level');
            }
            if (
                ($videoCodec === 'h264' && $level > 5.2)
                || ($videoCodec === 'hevc' && $level > 6.2)
            ) {
                return $this->transcode('video-level-not-supported');
            }

            $dynamicRange = $this->normalizeDynamicRange(
                $technical['dynamic_range'] ?? null,
                $bitDepth,
            );
            if ($dynamicRange === '') {
                return $this->transcode('unknown-video-dynamic-range');
            }
            $dynamicRanges = array_map(
                $this->normalizeDynamicRange(...),
                $capabilities['dynamicRanges'] ?? [],
            );
            if (! in_array($dynamicRange, $dynamicRanges, true)) {
                return $this->transcode('video-dynamic-range-not-supported');
            }

            if (($preferences['maximumBitrate'] ?? null) !== null) {
                $bitRate = $this->positiveInteger(
                    $technical['bit_rate'] ?? null,
                );
                if ($bitRate === null) {
                    return $this->transcode('unknown-media-bitrate');
                }
                if ($bitRate > (int) $preferences['maximumBitrate']) {
                    return $this->transcode(
                        'media-bitrate-exceeds-user-preference',
                    );
                }
            }
        }

        if (($preferences['selectedSubtitle'] ?? false) === true) {
            if (! in_array(
                'webvtt',
                $capabilities['subtitleFormats'] ?? [],
                true,
            )) {
                return $this->transcode('selected-subtitle-format-not-supported');
            }

            if ($containerCompatible && $audioCompatible) {
                return [
                    'mode' => 'remux',
                    'reason' => 'selected-subtitle-requires-hls-master',
                    'container' => 'hls',
                ];
            }
        }

        if (
            ($preferences['selectedAudio'] ?? false) === true
            && $containerCompatible
            && $videoCompatible
            && $audioCompatible
        ) {
            return [
                'mode' => 'remux',
                'reason' => 'selected-audio-requires-hls-rendition',
                'container' => 'hls',
            ];
        }

        if ($containerCompatible && $videoCompatible && $audioCompatible) {
            return [
                'mode' => 'direct',
                'reason' => 'compatible-native-container-and-codecs',
                'container' => $container,
            ];
        }

        if ($videoCompatible && $audioCompatible) {
            return [
                'mode' => 'remux',
                'reason' => 'compatible-codecs-require-server-repackaging',
                'container' => 'hls',
            ];
        }

        if ($videoCompatible && ! $audioCompatible) {
            return [
                'mode' => 'audioTranscode',
                'reason' => 'audio-codec-not-supported',
                'container' => 'hls',
            ];
        }

        return [
            'mode' => 'fullTranscode',
            'reason' => 'video-codec-not-supported',
            'container' => 'hls',
        ];
    }

    /**
     * @return array{mode: string, reason: string, container: string}
     */
    private function transcode(string $reason): array
    {
        return [
            'mode' => 'fullTranscode',
            'reason' => $reason,
            'container' => 'hls',
        ];
    }

    private function positiveInteger(mixed $value): ?int
    {
        if (! is_numeric($value) || (int) $value <= 0) {
            return null;
        }

        return (int) $value;
    }

    private function positiveFloat(mixed $value): ?float
    {
        if (! is_numeric($value) || (float) $value <= 0) {
            return null;
        }

        return (float) $value;
    }

    private function normalizeProfile(mixed $profile): string
    {
        return preg_replace(
            '/[^a-z0-9]/',
            '',
            strtolower(trim((string) $profile)),
        ) ?? '';
    }

    private function normalizedLevel(
        mixed $level,
        string $codec,
    ): ?float {
        if (! is_numeric($level) || (float) $level <= 0) {
            return null;
        }
        $numeric = (float) $level;
        if ($numeric <= 10) {
            return $numeric;
        }

        return $codec === 'hevc' ? $numeric / 30 : $numeric / 10;
    }

    private function normalizeDynamicRange(
        mixed $range,
        ?int $bitDepth = null,
    ): string {
        $normalized = strtolower(trim((string) $range));

        return match ($normalized) {
            'sdr', 'bt709' => 'sdr',
            'hdr10', 'smpte2084', 'pq' => 'hdr10',
            'hdr10+', 'hdr10plus' => 'hdr10Plus',
            'hlg', 'arib-std-b67' => 'hlg',
            'dolbyvision', 'dolby vision', 'dv' => 'dolbyVision',
            '' => $bitDepth !== null && $bitDepth <= 8 ? 'sdr' : '',
            default => '',
        };
    }

    private function normalizeCodec(mixed $codec): string
    {
        return match (strtolower(trim((string) $codec))) {
            'avc', 'avc1', 'h.264' => 'h264',
            'h.265', 'h265', 'hev1', 'hvc1' => 'hevc',
            'e-ac-3', 'e_ac_3' => 'eac3',
            'ac-3' => 'ac3',
            default => strtolower(trim((string) $codec)),
        };
    }
}
