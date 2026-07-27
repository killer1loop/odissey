<?php

namespace App\Services\Media;

use App\Models\TranscodeSession;
use FilesystemIterator;
use Illuminate\Support\Facades\Log;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Throwable;

class TranscodePruner
{
    public function __construct(private readonly TranscodeStorage $storage) {}

    /**
     * @return array{sessions: int, orphan_directories: int, transient_files: int, bytes: int}
     */
    public function prune(bool $dryRun = false): array
    {
        $now = now();
        $failedCutoff = $now->copy()->subMinutes(
            max(0, (int) config('odissey.transcode_failed_retention_minutes', 60)),
        );
        $staleCutoff = $now->copy()->subMinutes(
            max(1, (int) config('odissey.transcode_stale_minutes', 15)),
        );

        $sessions = TranscodeSession::query()
            ->where(function ($query) use ($failedCutoff, $now, $staleCutoff): void {
                $query
                    ->where(function ($query) use ($now): void {
                        $query
                            ->where('status', TranscodeSession::STATUS_READY)
                            ->whereNotNull('expires_at')
                            ->where('expires_at', '<=', $now);
                    })
                    ->orWhere(function ($query) use ($failedCutoff): void {
                        $query
                            ->where('status', TranscodeSession::STATUS_FAILED)
                            ->where('updated_at', '<=', $failedCutoff);
                    })
                    ->orWhere(function ($query) use ($staleCutoff): void {
                        $query
                            ->where(function ($query): void {
                                $query
                                    ->whereIn('status', [
                                        TranscodeSession::STATUS_PENDING,
                                        TranscodeSession::STATUS_PROCESSING,
                                    ])
                                    ->orWhere(function ($query): void {
                                        $query
                                            ->where(
                                                'status',
                                                TranscodeSession::STATUS_READY,
                                            )
                                            ->whereNull('finished_at');
                                    });
                            })
                            ->where(function ($query) use ($staleCutoff): void {
                                $query
                                    ->where('heartbeat_at', '<=', $staleCutoff)
                                    ->orWhere(function ($query) use ($staleCutoff): void {
                                        $query
                                            ->whereNull('heartbeat_at')
                                            ->where(function ($query) use ($staleCutoff): void {
                                                $query
                                                    ->where('started_at', '<=', $staleCutoff)
                                                    ->orWhere(function ($query) use ($staleCutoff): void {
                                                        $query
                                                            ->whereNull('started_at')
                                                            ->where('updated_at', '<=', $staleCutoff);
                                                    });
                                            });
                                    });
                            });
                    });
            })
            ->get();

        $result = [
            'sessions' => 0,
            'orphan_directories' => 0,
            'transient_files' => 0,
            'bytes' => 0,
        ];

        foreach ($sessions as $session) {
            $bytes = $this->storage->bytesFor($session);

            if ($dryRun) {
                $result['sessions']++;
                $result['bytes'] += $bytes;

                continue;
            }

            try {
                $this->storage->delete($session);
                $session->delete();
                $result['sessions']++;
                $result['bytes'] += $bytes;
            } catch (Throwable $exception) {
                Log::warning('Transient transcode cleanup failed.', [
                    'session_id' => $session->getKey(),
                    'exception' => $exception::class,
                ]);
            }
        }

        $directories = $this->storage->sessionDirectories();
        $knownIds = TranscodeSession::query()
            ->whereIn('id', array_keys($directories))
            ->pluck('id')
            ->all();
        $knownLookup = array_fill_keys($knownIds, true);

        foreach ($directories as $sessionId => $lastModified) {
            if (isset($knownLookup[$sessionId]) || $lastModified > $staleCutoff->timestamp) {
                continue;
            }

            if ($dryRun) {
                $result['orphan_directories']++;

                continue;
            }

            try {
                $this->storage->deleteById($sessionId);
                $result['orphan_directories']++;
            } catch (Throwable $exception) {
                Log::warning('Orphan transcode cleanup failed.', [
                    'session_id' => $sessionId,
                    'exception' => $exception::class,
                ]);
            }
        }

        $this->pruneTransientFiles(
            'sources',
            $staleCutoff->timestamp,
            $dryRun,
            $result,
        );
        $this->pruneTransientFiles(
            'subtitles',
            $now->copy()->subMinutes(
                min(
                    43200,
                    max(10, (int) config('odissey.embedded_subtitle_cache_minutes', 1440)),
                ),
            )->timestamp,
            $dryRun,
            $result,
        );

        return $result;
    }

    /**
     * @param  array{sessions: int, orphan_directories: int, transient_files: int, bytes: int}  $result
     */
    private function pruneTransientFiles(
        string $area,
        int $cutoff,
        bool $dryRun,
        array &$result,
    ): void {
        $directory = $this->storage->transientDirectory($area);
        if (! is_dir($directory)) {
            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $entry) {
            try {
                if ($entry->isLink()) {
                    continue;
                }

                if ($entry->isDir()) {
                    if (! $dryRun) {
                        @rmdir($entry->getPathname());
                    }

                    continue;
                }

                if (! $entry->isFile() || $entry->getMTime() > $cutoff) {
                    continue;
                }

                $reservationHandle = null;
                if (str_ends_with($entry->getFilename(), '.reserve')) {
                    $reservationHandle = @fopen(
                        $entry->getPathname(),
                        'rb',
                    );
                    if (
                        ! is_resource($reservationHandle)
                        || ! flock(
                            $reservationHandle,
                            LOCK_EX | LOCK_NB,
                        )
                    ) {
                        if (is_resource($reservationHandle)) {
                            fclose($reservationHandle);
                        }

                        continue;
                    }
                }

                try {
                    $bytes = $entry->getSize();
                    $result['transient_files']++;
                    $result['bytes'] += $bytes;

                    if (! $dryRun && ! @unlink($entry->getPathname())) {
                        Log::warning(
                            'Transient media file cleanup failed.',
                            [
                                'area' => $area,
                                'file' => basename(
                                    $entry->getPathname(),
                                ),
                            ],
                        );
                    }
                } finally {
                    if (is_resource($reservationHandle)) {
                        flock($reservationHandle, LOCK_UN);
                        fclose($reservationHandle);
                    }
                }
            } catch (Throwable $exception) {
                Log::warning('Transient media file inspection failed.', [
                    'area' => $area,
                    'exception' => $exception::class,
                ]);
            }
        }
    }
}
