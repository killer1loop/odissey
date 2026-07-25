<?php

namespace App\Http\Controllers\Media\Admin;

use App\Http\Controllers\Controller;
use App\Models\IntegrationSetting;
use App\Services\IntegrationSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class IntegrationController extends Controller
{
    public function edit(IntegrationSettings $settings): View
    {
        return view('media.admin.integrations', [
            'configured' => [
                'tmdb_api_token' => $settings->has('tmdb_api_token', config('services.tmdb.token')),
                'subdl_api_key' => $settings->has('subdl_api_key', config('services.subdl.api_key')),
                'opensubtitles_api_key' => $settings->has('opensubtitles_api_key', config('services.opensubtitles.api_key')),
            ],
            'languages' => $settings->get('caption_languages', implode(',', config('odissey.caption_languages'))),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'tmdb_api_token' => ['nullable', 'string', 'max:4096'],
            'subdl_api_key' => ['nullable', 'string', 'max:4096'],
            'opensubtitles_api_key' => ['nullable', 'string', 'max:4096'],
            'caption_languages' => ['required', 'regex:/^[a-zA-Z]{2,3}(,[a-zA-Z]{2,3})*$/', 'max:100'],
            'clear' => ['array'],
            'clear.*' => ['in:tmdb_api_token,subdl_api_key,opensubtitles_api_key'],
        ]);
        foreach (['tmdb_api_token', 'subdl_api_key', 'opensubtitles_api_key'] as $key) {
            if (in_array($key, $data['clear'] ?? [], true)) {
                IntegrationSetting::query()->whereKey($key)->delete();
            } elseif (! empty($data[$key])) {
                IntegrationSetting::query()->updateOrCreate(['key' => $key], ['value' => $data[$key]]);
            }
        }
        IntegrationSetting::query()->updateOrCreate(['key' => 'caption_languages'], ['value' => strtolower($data['caption_languages'])]);

        return back()->with('status', 'Metadata and caption integrations saved.');
    }
}
