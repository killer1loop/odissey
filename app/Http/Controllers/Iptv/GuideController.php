<?php

namespace App\Http\Controllers\Iptv;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class GuideController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        return redirect()->route('iptv.channels.index', [
            'q' => $request->string('q')->limit(100)->toString() ?: null,
            'group' => $request->integer('group') ?: null,
            'favorites' => $request->boolean('favorites') ? 1 : null,
            'view' => 'guide',
        ]);
    }
}
