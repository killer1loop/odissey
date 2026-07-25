<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class EnsureSetupIsAvailable
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $setupCompleted = DB::table('installation_states')
            ->where('key', 'initial_setup')
            ->whereNotNull('completed_at')
            ->exists();

        abort_if($setupCompleted || User::query()->exists(), Response::HTTP_NOT_FOUND);

        return $next($request);
    }
}
