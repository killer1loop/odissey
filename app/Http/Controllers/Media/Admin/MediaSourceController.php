<?php

namespace App\Http\Controllers\Media\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\Media\ScanMediaSource;
use App\Models\MediaSource;
use App\Services\Media\Sources\MediaSourceRegistry;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Throwable;

class MediaSourceController extends Controller
{
    public function index(): View
    {
        return view('media.admin.sources.index', ['sources' => MediaSource::withCount('items')->orderBy('name')->get()]);
    }

    public function create(): View
    {
        return view('media.admin.sources.form', ['source' => new MediaSource]);
    }

    public function store(Request $request, MediaSourceRegistry $registry): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100', 'unique:media_sources,name'],
            'type' => ['required', Rule::in([MediaSource::TYPE_LOCAL, MediaSource::TYPE_S3, MediaSource::TYPE_WEBDAV])],
            'path' => ['nullable', 'string', 'max:2048'],
            'url' => ['nullable', 'url:http,https', 'max:2048'],
            'endpoint' => ['nullable', 'url:http,https', 'max:2048'],
            'bucket' => ['nullable', 'string', 'max:255'],
            'prefix' => ['nullable', 'string', 'max:1024'],
            'region' => ['nullable', 'string', 'max:64'],
            'access_key' => ['nullable', 'string', 'max:512'],
            'secret_key' => ['nullable', 'string', 'max:512'],
            'username' => ['nullable', 'string', 'max:512'],
            'password' => ['nullable', 'string', 'max:512'],
            'allow_private_network' => ['boolean'],
        ]);
        $configuration = match ($data['type']) {
            'local' => ['path' => $data['path'] ?? ''],
            's3' => collect($data)->only(['endpoint', 'bucket', 'prefix', 'region', 'access_key', 'secret_key'])->all(),
            'webdav' => collect($data)->only(['url', 'username', 'password'])->all(),
        };
        $source = MediaSource::create([
            'name' => $data['name'], 'type' => $data['type'], 'configuration' => $configuration,
            'allow_private_network' => $request->boolean('allow_private_network'),
        ]);
        try {
            $source->update(['capabilities' => $registry->for($source)->capabilities($source)]);
        } catch (Throwable) {
            $source->delete();
            throw ValidationException::withMessages(['name' => 'The read-only source could not be reached or validated.']);
        }
        ScanMediaSource::dispatch($source->id);

        return redirect()->route('media.admin.sources.index')->with('status', 'Source validated. Its initial scan is queued.');
    }

    public function scan(MediaSource $source): RedirectResponse
    {
        abort_if($source->type === MediaSource::TYPE_IPTV, 404);
        ScanMediaSource::dispatch($source->id);

        return back()->with('status', 'Library scan queued.');
    }

    public function destroy(MediaSource $source): RedirectResponse
    {
        abort_if($source->type === MediaSource::TYPE_IPTV, 404);
        $source->items()->eachById(fn ($item) => $item->delete());
        $source->delete();

        return back()->with('status', 'Source metadata was removed. No source file was changed.');
    }
}
