<?php

namespace App\Services\Media;

use App\Models\MediaItem;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use RuntimeException;

class MediaFixtureGenerator
{
    public function __construct(
        private readonly FfmpegRunner $runner,
        private readonly FfmpegArguments $arguments,
        private readonly TranscodeStorage $transcodeStorage,
    ) {}

    /**
     * @return Collection<int, MediaItem>
     */
    public function generate(User $user, int $durationSeconds): Collection
    {
        $root = $this->root();
        $this->clean();
        File::ensureDirectoryExists($root, 0755, true);
        File::chmod($root, 0755);

        $directPath = $root.DIRECTORY_SEPARATOR.'direct-play.mp4';
        $incompatiblePath = $root.DIRECTORY_SEPARATOR.'requires-transcode.mkv';

        $this->runner->run(
            $this->arguments->directPlayFixture($directPath, $durationSeconds),
            120,
        );
        $this->runner->run(
            $this->arguments->incompatibleFixture($incompatiblePath, $durationSeconds),
            120,
        );

        if (! File::isFile($directPath) || ! File::isFile($incompatiblePath)) {
            throw new RuntimeException('FFmpeg did not produce both media fixtures.');
        }

        File::chmod($directPath, 0644);
        File::chmod($incompatiblePath, 0644);

        $durationMs = $durationSeconds * 1000;

        return collect([
            MediaItem::query()->create([
                'user_id' => $user->getKey(),
                'title' => 'E2E Direct Play',
                'source_type' => 'local',
                'source_locator' => $directPath,
                'mime_type' => 'video/mp4',
                'container' => 'mp4',
                'video_codec' => 'h264',
                'audio_codec' => 'aac',
                'duration_ms' => $durationMs,
                'requires_transcode' => false,
            ]),
            MediaItem::query()->create([
                'user_id' => $user->getKey(),
                'title' => 'E2E FFmpeg Transcode',
                'source_type' => 'local',
                'source_locator' => $incompatiblePath,
                'mime_type' => 'video/x-matroska',
                'container' => 'matroska',
                'video_codec' => 'ffv1',
                'audio_codec' => 'pcm_s16le',
                'duration_ms' => $durationMs,
                'requires_transcode' => true,
            ]),
        ]);
    }

    public function clean(): int
    {
        $root = $this->root();
        $deleted = 0;

        MediaItem::query()
            ->where('source_type', 'local')
            ->eachById(function (MediaItem $item) use ($root, &$deleted): void {
                if ($this->isWithinRoot($item->source_locator, $root)) {
                    $item->transcodeSessions()
                        ->each(fn ($session) => $this->transcodeStorage->delete($session));
                    $item->delete();
                    $deleted++;
                }
            });

        if (File::isDirectory($root) && ! File::deleteDirectory($root)) {
            throw new RuntimeException('The E2E fixture directory could not be removed.');
        }

        return $deleted;
    }

    private function root(): string
    {
        $root = rtrim((string) config('odissey.e2e_path'), DIRECTORY_SEPARATOR);
        $segments = explode(DIRECTORY_SEPARATOR, $root);

        if (
            $root === ''
            || $root === DIRECTORY_SEPARATOR
            || ! str_starts_with($root, DIRECTORY_SEPARATOR)
            || str_contains($root, "\0")
            || str_contains($root, DIRECTORY_SEPARATOR.DIRECTORY_SEPARATOR)
            || in_array('.', $segments, true)
            || in_array('..', $segments, true)
            || is_link($root)
        ) {
            throw new RuntimeException('The E2E fixture path must be a safe absolute path.');
        }

        return $root;
    }

    private function isWithinRoot(string $path, string $root): bool
    {
        return $path === $root || str_starts_with($path, $root.DIRECTORY_SEPARATOR);
    }
}
