<?php

namespace App\Http\Controllers\Iptv;

use App\Http\Controllers\Controller;
use App\Models\Iptv\IptvPlaybackSession;
use App\Services\Iptv\PlaybackAccess;
use App\Services\Iptv\PlaybackAttemptRecorder;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class PlaybackDiagnosticController extends Controller
{
    public function __invoke(
        Request $request,
        IptvPlaybackSession $session,
        PlaybackAccess $access,
        PlaybackAttemptRecorder $attempts,
    ): Response {
        $access->assertSession($request->user(), $session);
        $validated = $request->validate([
            'error_code' => [
                'required',
                'string',
                'max:64',
                'regex:/^[a-zA-Z0-9_.-]+$/',
            ],
            'http_status' => [
                'nullable',
                'integer',
                'min:100',
                'max:599',
            ],
        ]);

        $attempts->record(
            $session,
            'failed',
            $validated['http_status'] ?? null,
            'client_'.strtolower($validated['error_code']),
            terminalOnThreshold: false,
        );

        return response()->noContent();
    }
}
