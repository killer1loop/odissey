<?php

namespace Tests\Unit\Media;

use App\Models\MediaItem;
use App\Models\MediaSource;
use App\Services\Media\MediaProbe;
use App\Services\Media\MediaProcessFactory;
use App\Services\Media\RemoteMediaProbe;
use App\Services\Media\Sources\MediaSourceAdapter;
use App\Services\Media\Sources\MediaSourceRegistry;
use App\Services\Media\Sources\SourceResponse;
use Illuminate\Support\Carbon;
use Mockery;
use RuntimeException;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class RemoteMediaProbeTest extends TestCase
{
    public function test_failed_probe_attempt_uses_a_bounded_retry_cooldown(): void
    {
        Carbon::setTestNow('2026-07-30 12:00:00');
        config(['odissey.remote_probe_retry_days' => 30]);
        $probe = new RemoteMediaProbe(
            Mockery::mock(MediaSourceRegistry::class),
            Mockery::mock(MediaProbe::class),
        );
        $item = new MediaItem([
            'metadata' => [
                'technical_probe_attempt_version' => RemoteMediaProbe::VERSION,
                'technical_probe_attempted_at' => now()
                    ->subDays(29)
                    ->toIso8601String(),
            ],
        ]);

        $this->assertFalse($probe->shouldAttempt($item));

        $item->metadata = [
            'technical_probe_attempt_version' => RemoteMediaProbe::VERSION,
            'technical_probe_attempted_at' => now()
                ->subDays(30)
                ->toIso8601String(),
        ];
        $this->assertTrue($probe->shouldAttempt($item));

        Carbon::setTestNow();
    }

    public function test_remote_probe_reads_only_the_bounded_range_and_closes_it(): void
    {
        $maximumBytes = 16 * 1024 * 1024;
        config(['odissey.remote_probe_max_bytes' => $maximumBytes]);
        $body = fopen('php://temp', 'w+b');
        $this->assertIsResource($body);
        foreach (range(1, 17) as $_) {
            fwrite($body, str_repeat('x', 1024 * 1024));
        }
        rewind($body);

        $adapter = new class($body) implements MediaSourceAdapter
        {
            public ?int $start = null;

            public ?int $end = null;

            public function __construct(private mixed $body) {}

            public function objects(MediaSource $source): iterable
            {
                return [];
            }

            public function capabilities(MediaSource $source): array
            {
                return [];
            }

            public function open(
                MediaSource $source,
                string $locator,
                ?int $start,
                ?int $end,
            ): SourceResponse {
                $this->start = $start;
                $this->end = $end;

                return new SourceResponse(
                    $this->body,
                    206,
                    16 * 1024 * 1024,
                    'video/mp4',
                    'bytes 0-16777215/17825792',
                );
            }

            public function localPath(
                MediaSource $source,
                string $locator,
            ): ?string {
                return null;
            }
        };
        $registry = new class($adapter) extends MediaSourceRegistry
        {
            public function __construct(
                private readonly MediaSourceAdapter $adapter,
            ) {}

            public function for(MediaSource $source): MediaSourceAdapter
            {
                return $this->adapter;
            }
        };
        $technical = $this->technicalResult();
        $observedBytes = 0;
        $probe = Mockery::mock(MediaProbe::class);
        $probe->shouldReceive('inspectInput')
            ->once()
            ->withArgs(function (mixed $input, string $path) use (
                &$observedBytes,
            ): bool {
                foreach ($input as $chunk) {
                    $observedBytes += strlen($chunk);
                }

                return $path === 'Movies/Compatible.mp4';
            })
            ->andReturn($technical);
        $source = new MediaSource(['type' => MediaSource::TYPE_S3]);

        $result = (new RemoteMediaProbe($registry, $probe))->inspect(
            $source,
            'private/provider-secret/Compatible.mp4',
            'Movies/Compatible.mp4',
        );

        $this->assertSame($technical, $result);
        $this->assertSame(0, $adapter->start);
        $this->assertSame($maximumBytes - 1, $adapter->end);
        $this->assertSame($maximumBytes, $observedBytes);
        $this->assertFalse(is_resource($body));
    }

    public function test_remote_probe_failure_is_fail_closed_and_closes_the_body(): void
    {
        $body = fopen('php://temp', 'w+b');
        $this->assertIsResource($body);
        fwrite($body, 'bounded bytes');
        rewind($body);
        $adapter = $this->adapterReturning(new SourceResponse(
            $body,
            206,
            13,
            'video/mp4',
            'bytes 0-12/13',
        ));
        $probe = Mockery::mock(MediaProbe::class);
        $probe->shouldReceive('inspectInput')
            ->once()
            ->andThrow(new RuntimeException('synthetic probe failure'));

        $result = (new RemoteMediaProbe(
            $this->registryReturning($adapter),
            $probe,
        ))->inspect(
            new MediaSource(['type' => MediaSource::TYPE_WEBDAV]),
            'Compatible.mp4',
            'Compatible.mp4',
        );

        $this->assertNull($result);
        $this->assertFalse(is_resource($body));
    }

    public function test_ffprobe_receives_pipe_input_without_the_remote_locator(): void
    {
        $output = json_encode([
            'streams' => [
                [
                    'index' => 0,
                    'codec_type' => 'video',
                    'codec_name' => 'h264',
                    'profile' => 'Main',
                    'level' => 41,
                    'width' => 1920,
                    'height' => 1080,
                    'avg_frame_rate' => '24/1',
                    'bit_rate' => '6000000',
                    'pix_fmt' => 'yuv420p',
                    'color_transfer' => 'bt709',
                ],
                [
                    'index' => 1,
                    'codec_type' => 'audio',
                    'codec_name' => 'aac',
                    'channels' => 2,
                ],
            ],
            'format' => [
                'duration' => '120.5',
                'format_name' => 'mov,mp4,m4a,3gp,3g2,mj2',
                'bit_rate' => '6500000',
            ],
        ], JSON_THROW_ON_ERROR);
        $factory = new class($output) extends MediaProcessFactory
        {
            /** @var list<string> */
            public array $requestedArguments = [];

            public function __construct(private readonly string $output) {}

            public function make(
                array $arguments,
                int $timeoutSeconds,
            ): Process {
                $this->requestedArguments = $arguments;

                return parent::make([
                    PHP_BINARY,
                    '-r',
                    'file_get_contents("php://stdin");'
                        .'fwrite(STDOUT, '.var_export($this->output, true).');',
                ], $timeoutSeconds);
            }
        };
        $secret = 'provider-user:provider-password';
        $input = (static function () use ($secret) {
            yield 'media bytes '.$secret;
        })();

        $result = (new MediaProbe($factory))->inspectInput(
            $input,
            'Compatible.mp4',
        );

        $this->assertIsArray($result);
        $this->assertSame('h264', $result['video_codec']);
        $this->assertSame('aac', $result['audio_codec']);
        $this->assertSame(120500, $result['duration_ms']);
        $this->assertSame('pipe:0', $factory->requestedArguments[array_key_last(
            $factory->requestedArguments,
        )]);
        $this->assertStringNotContainsString(
            $secret,
            implode(' ', $factory->requestedArguments),
        );
        $whitelist = array_search(
            '-protocol_whitelist',
            $factory->requestedArguments,
            true,
        );
        $this->assertIsInt($whitelist);
        $this->assertSame('pipe', $factory->requestedArguments[$whitelist + 1]);
        $this->assertNotContains('file', $factory->requestedArguments);
    }

    private function adapterReturning(
        SourceResponse $response,
    ): MediaSourceAdapter {
        return new class($response) implements MediaSourceAdapter
        {
            public function __construct(
                private readonly SourceResponse $response,
            ) {}

            public function objects(MediaSource $source): iterable
            {
                return [];
            }

            public function capabilities(MediaSource $source): array
            {
                return [];
            }

            public function open(
                MediaSource $source,
                string $locator,
                ?int $start,
                ?int $end,
            ): SourceResponse {
                return $this->response;
            }

            public function localPath(
                MediaSource $source,
                string $locator,
            ): ?string {
                return null;
            }
        };
    }

    private function registryReturning(
        MediaSourceAdapter $adapter,
    ): MediaSourceRegistry {
        return new class($adapter) extends MediaSourceRegistry
        {
            public function __construct(
                private readonly MediaSourceAdapter $adapter,
            ) {}

            public function for(MediaSource $source): MediaSourceAdapter
            {
                return $this->adapter;
            }
        };
    }

    /** @return array<string, mixed> */
    private function technicalResult(): array
    {
        return [
            'media_kind' => 'video',
            'mime_type' => 'video/mp4',
            'container' => 'mp4',
            'video_codec' => 'h264',
            'audio_codec' => 'aac',
            'duration_ms' => 120000,
            'requires_transcode' => false,
            'technical' => [
                'width' => 1920,
                'height' => 1080,
                'frame_rate' => 24.0,
                'bit_rate' => '6500000',
                'video_profile' => 'Main',
                'video_level' => 41,
                'bit_depth' => 8,
                'dynamic_range' => 'sdr',
            ],
            'tags' => [],
        ];
    }
}
