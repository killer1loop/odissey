<?php

namespace App\Console\Commands;

use App\Models\TranscodeSession;
use App\Services\Media\TranscodeStorage;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

class RecoverMediaTranscodes extends Command
{
    protected $signature = 'media:transcodes:recover';

    protected $description = 'Fail transcodes interrupted by a previous runtime and clear their orphaned jobs';

    public function handle(TranscodeStorage $storage): int
    {
        $sessions = TranscodeSession::query()
            ->where(function ($query): void {
                $query
                    ->whereIn('status', [
                        TranscodeSession::STATUS_PENDING,
                        TranscodeSession::STATUS_PROCESSING,
                    ])
                    ->orWhere(function ($query): void {
                        $query
                            ->where('status', TranscodeSession::STATUS_READY)
                            ->whereNull('finished_at');
                    });
            })
            ->get();

        foreach ($sessions as $session) {
            try {
                $storage->delete($session);
            } catch (Throwable) {
                // The transient directory may already have disappeared.
            }

            $session->update([
                'status' => TranscodeSession::STATUS_FAILED,
                'manifest_relative_path' => null,
                'error_code' => 'transcode_interrupted',
                'heartbeat_at' => now(),
                'finished_at' => now(),
                'expires_at' => null,
            ]);
        }

        $jobs = DB::table('jobs')
            ->where('queue', 'transcodes')
            ->delete();
        $legacyJobs = DB::table('jobs')
            ->where('queue', 'high')
            ->where('payload', 'like', '%TranscodeMediaToHls%')
            ->delete();

        $this->info(sprintf(
            'Recovered %d transcode session(s) and removed %d orphaned job(s).',
            $sessions->count(),
            $jobs + $legacyJobs,
        ));

        return self::SUCCESS;
    }
}
