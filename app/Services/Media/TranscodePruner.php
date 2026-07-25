<?php

namespace App\Services\Media;

use App\Models\TranscodeSession;
use Illuminate\Support\Facades\Log;
use Throwable;

class TranscodePruner
{
    public function __construct(private readonly TranscodeStorage $storage) {}

    /**
     * @return array{sessions: int, orphan_directories: int, bytes: int}
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
                            ->where('status', TranscodeSession::STATUS_PROCESSING)
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
            })
            ->get();

        $result = [
            'sessions' => 0,
            'orphan_directories' => 0,
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

        return $result;
    }
}
