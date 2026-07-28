<?php

namespace App\Services\Api;

use App\Models\AdminAuditEvent;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

class AdminAuditService
{
    public function record(
        Request $request,
        string $action,
        ?Model $subject = null,
    ): string {
        $event = AdminAuditEvent::query()->create([
            'user_id' => $request->user()->getKey(),
            'native_client_session_id' => $request->attributes->get(
                'nativeClientSession',
            )?->getKey(),
            'action' => $action,
            'subject_type' => $subject?->getMorphClass(),
            'subject_id' => $subject === null
                ? null
                : (string) $subject->getKey(),
            'request_id' => $request->attributes->get('requestId'),
        ]);

        $this->enforceMaximumRows();

        return (string) $event->getKey();
    }

    private function enforceMaximumRows(): void
    {
        $maximum = (int) config('native-client.maximum_admin_audit_events');
        $overflow = AdminAuditEvent::query()->count() - $maximum;
        $batch = (int) config('native-client.prune_batch_size');

        while ($overflow > 0) {
            $ids = AdminAuditEvent::query()
                ->oldest('created_at')
                ->oldest('id')
                ->limit(min($overflow, $batch))
                ->pluck('id');
            if ($ids->isEmpty()) {
                break;
            }
            AdminAuditEvent::query()->whereKey($ids)->delete();
            $overflow -= $ids->count();
        }
    }
}
