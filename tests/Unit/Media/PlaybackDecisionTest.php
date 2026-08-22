<?php

namespace Tests\Unit\Media;

use App\Models\MediaItem;
use App\Services\Media\PlaybackDecision;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class PlaybackDecisionTest extends TestCase
{
    /**
     * @param  array<string, mixed>  $technical
     * @param  array<string, mixed>  $overrides
     */
    #[DataProvider('incompatibleMedia')]
    public function test_native_decisions_fail_closed_with_explicit_reasons(
        array $technical,
        array $overrides,
        array $preferences,
        string $reason,
    ): void {
        $item = $this->item($technical, $overrides);
        $decision = (new PlaybackDecision)->forNative(
            $item,
            $this->capabilities(),
            $preferences,
        );

        $this->assertSame('fullTranscode', $decision['mode']);
        $this->assertSame($reason, $decision['reason']);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, array<string, mixed>, array<string, mixed>, string}>
     */
    public static function incompatibleMedia(): iterable
    {
        yield 'AV1 is never claimed as compatible' => [
            [],
            ['video_codec' => 'av1'],
            [],
            'av1-is-not-declared-native-compatible',
        ];
        yield 'missing dimensions' => [
            ['width' => null],
            [],
            [],
            'unknown-video-dimensions',
        ];
        yield 'oversize dimensions' => [
            ['width' => 7680],
            [],
            [],
            'video-dimensions-exceed-device-limit',
        ];
        yield 'missing frame rate' => [
            ['frame_rate' => null],
            [],
            [],
            'unknown-video-frame-rate',
        ];
        yield 'high frame rate' => [
            ['frame_rate' => 120],
            [],
            [],
            'video-frame-rate-exceeds-device-limit',
        ];
        yield 'unsupported bit depth' => [
            ['bit_depth' => 10],
            [],
            [],
            'video-bit-depth-not-supported',
        ];
        yield 'missing profile' => [
            ['video_profile' => null],
            [],
            [],
            'unknown-video-profile',
        ];
        yield 'unsupported level' => [
            ['video_level' => 6.1],
            [],
            [],
            'video-level-not-supported',
        ];
        yield 'unsupported dynamic range' => [
            ['dynamic_range' => 'dolbyVision'],
            [],
            [],
            'video-dynamic-range-not-supported',
        ];
        yield 'unknown capped bitrate' => [
            ['bit_rate' => null],
            [],
            ['maximumBitrate' => 5_000_000],
            'unknown-media-bitrate',
        ];
        yield 'over capped bitrate' => [
            ['bit_rate' => 8_000_000],
            [],
            ['maximumBitrate' => 5_000_000],
            'media-bitrate-exceeds-user-preference',
        ];
    }

    public function test_compatible_media_uses_direct_play_and_subtitles_use_remux(): void
    {
        $decision = new PlaybackDecision;
        $item = $this->item();

        $this->assertSame(
            'direct',
            $decision->forNative($item, $this->capabilities())['mode'],
        );
        $subtitle = $decision->forNative(
            $item,
            $this->capabilities(),
            ['selectedSubtitle' => true],
        );
        $this->assertSame('remux', $subtitle['mode']);
        $this->assertSame(
            'selected-subtitle-requires-hls-master',
            $subtitle['reason'],
        );
        $selectedAudio = $decision->forNative(
            $item,
            $this->capabilities(),
            [
                'selectedAudio' => true,
                'selectedAudioCodec' => 'aac',
            ],
        );
        $this->assertSame('remux', $selectedAudio['mode']);
        $this->assertSame(
            'selected-audio-requires-hls-rendition',
            $selectedAudio['reason'],
        );
    }

    public function test_nullable_bitrate_preference_does_not_create_a_zero_bitrate_cap(): void
    {
        $decision = (new PlaybackDecision)->forNative(
            $this->item(['bit_rate' => null]),
            $this->capabilities(),
            ['maximumBitrate' => null],
        );

        $this->assertSame('direct', $decision['mode']);
        $this->assertSame(
            'compatible-native-container-and-codecs',
            $decision['reason'],
        );
    }

    public function test_web_delivery_mode_copies_h264_video_and_converts_only_audio(): void
    {
        $decision = new PlaybackDecision;

        $this->assertSame(
            'audioTranscode',
            $decision->deliveryModeFor(
                $this->item([], [
                    'video_codec' => 'h264',
                    'audio_codec' => 'eac3',
                ]),
            ),
        );
        $this->assertSame(
            'audioTranscode',
            $decision->deliveryModeFor(
                $this->item([], [
                    'video_codec' => 'H264',
                    'audio_codec' => 'dts',
                ]),
            ),
        );
        $this->assertSame(
            'fullTranscode',
            $decision->deliveryModeFor(
                $this->item([], [
                    'video_codec' => 'hevc',
                    'audio_codec' => 'eac3',
                ]),
            ),
        );
        $this->assertSame(
            'fullTranscode',
            $decision->deliveryModeFor(
                $this->item([], [
                    'video_codec' => null,
                    'audio_codec' => 'ac3',
                ]),
            ),
        );
        $this->assertSame(
            'fullTranscode',
            $decision->deliveryModeFor(
                $this->item([], [
                    'media_kind' => 'music',
                    'video_codec' => null,
                    'audio_codec' => 'flac',
                ]),
            ),
        );
    }

    /**
     * @param  array<string, mixed>  $technical
     * @param  array<string, mixed>  $overrides
     */
    private function item(
        array $technical = [],
        array $overrides = [],
    ): MediaItem {
        return new MediaItem([
            'media_kind' => 'video',
            'container' => 'mp4',
            'video_codec' => 'h264',
            'audio_codec' => 'aac',
            'metadata' => [
                'technical' => array_replace([
                    'width' => 1920,
                    'height' => 1080,
                    'frame_rate' => 24,
                    'bit_rate' => 8_000_000,
                    'video_profile' => 'Main',
                    'video_level' => 4.1,
                    'bit_depth' => 8,
                    'dynamic_range' => 'sdr',
                ], $technical),
            ],
            ...$overrides,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function capabilities(): array
    {
        return [
            'videoCodecs' => ['h264', 'hevc'],
            'audioCodecs' => ['aac', 'ac3', 'eac3'],
            'dynamicRanges' => ['sdr', 'hdr10', 'hlg'],
            'maximumWidth' => 3840,
            'maximumHeight' => 2160,
            'maximumFrameRate' => 60,
            'subtitleFormats' => ['webvtt'],
        ];
    }
}
