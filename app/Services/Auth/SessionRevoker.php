<?php

namespace App\Services\Auth;

use App\Models\User;
use Illuminate\Support\Facades\DB;

class SessionRevoker
{
    public function revokeDatabaseSessions(User $user): int
    {
        if (config('session.driver') !== 'database') {
            return 0;
        }

        $table = (string) config('session.table', 'sessions');
        $connection = config('session.connection');

        return DB::connection($connection)
            ->table($table)
            ->where('user_id', $user->getAuthIdentifier())
            ->delete();
    }
}
