<?php

namespace App\Console\Commands;

use App\Models\AdminAuditEvent;
use App\Models\NativeClientSession;
use App\Models\NativePlaybackGrant;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

class PruneNativeClientData extends Command
{
    protected $signature = 'native-client:prune
                            {--dry-run : Report eligible records without deleting them}';

    protected $description = 'Prune expired or revoked native sessions, grants, and refresh-token hashes';

    public function handle(): int
    {
        $batch = (int) config('native-client.prune_batch_size');
        $grantIds = $this->eligible(
            NativePlaybackGrant::query(),
            now()->subDays(
                (int) config(
                    'native-client.playback_grant_retention_days',
                ),
            ),
        )->limit($batch)->pluck('id');
        $sessionIds = $this->eligible(
            NativeClientSession::query(),
            now()->subDays(
                (int) config('native-client.session_retention_days'),
            ),
            'refresh_expires_at',
        )->limit($batch)->pluck('id');
        $auditEventIds = AdminAuditEvent::query()
            ->where(
                'created_at',
                '<',
                now()->subDays(
                    (int) config(
                        'native-client.admin_audit_retention_days',
                    ),
                ),
            )
            ->oldest('created_at')
            ->oldest('id')
            ->limit($batch)
            ->pluck('id');

        if (! $this->option('dry-run')) {
            NativePlaybackGrant::query()->whereKey($grantIds)->delete();
            NativeClientSession::query()->whereKey($sessionIds)->delete();
            AdminAuditEvent::query()->whereKey($auditEventIds)->delete();
        }

        $verb = $this->option('dry-run') ? 'Eligible' : 'Pruned';
        $this->info(sprintf(
            '%s %d native playback grant(s), %d native client session(s), and %d admin audit event(s).',
            $verb,
            $grantIds->count(),
            $sessionIds->count(),
            $auditEventIds->count(),
        ));

        return self::SUCCESS;
    }

    private function eligible(
        Builder $query,
        mixed $cutoff,
        string $expiryColumn = 'expires_at',
    ): Builder {
        return $query
            ->where(function (Builder $query) use (
                $cutoff,
                $expiryColumn,
            ): void {
                $query->where($expiryColumn, '<', $cutoff)
                    ->orWhere('revoked_at', '<', $cutoff);
            })
            ->orderBy($expiryColumn)
            ->orderBy('id');
    }
}
