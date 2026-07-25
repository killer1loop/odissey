<?php

namespace App\Http\Controllers;

use Illuminate\Contracts\View\View;

class FoundationStatusController extends Controller
{
    public function __invoke(): View
    {
        return view('partials.foundation-status', [
            'checks' => [
                ['label' => 'Laravel', 'value' => app()->version()],
                ['label' => 'Database', 'value' => 'SQLite'],
                ['label' => 'Interface', 'value' => 'Blade + HTMX 2'],
                ['label' => 'Streaming', 'value' => 'Direct + FFmpeg HLS'],
            ],
        ]);
    }
}
