<?php

namespace App\Http\Controllers\Media;

use App\Http\Controllers\Controller;
use App\Models\MediaItem;
use App\Services\Media\Sources\MediaSourceRegistry;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DirectMediaController extends Controller
{
    public function __invoke(Request $request, MediaSourceRegistry $registry, string $media): BinaryFileResponse|StreamedResponse|Response
    {
        $item = MediaItem::query()
            ->accessibleTo($request->user())
            ->with('source')
            ->findOrFail($media);

        abort_if($item->requires_transcode, 409);

        if ($item->source !== null) {
            $adapter = $registry->for($item->source);
            if ($localPath = $adapter->localPath($item->source, $item->source_locator)) {
                return response()->file($localPath, [
                    'Accept-Ranges' => 'bytes',
                    'Cache-Control' => 'private, no-store',
                    'Content-Type' => $item->mime_type ?: 'application/octet-stream',
                    'X-Content-Type-Options' => 'nosniff',
                ]);
            }
            $range = $request->header('Range');
            $start = $end = null;
            if ($range && preg_match('/^bytes=(\d+)-(\d*)$/', $range, $matches)) {
                $start = (int) $matches[1];
                $end = $matches[2] === '' ? null : (int) $matches[2];
            }
            $result = $adapter->open($item->source, $item->source_locator, $start, $end);
            $headers = [
                'Accept-Ranges' => ($item->source->capabilities['range'] ?? false) ? 'bytes' : 'none',
                'Cache-Control' => 'private, no-store',
                'Content-Type' => $result->contentType,
                'X-Content-Type-Options' => 'nosniff',
            ];
            if ($result->contentRange) {
                $headers['Content-Range'] = $result->contentRange;
            }
            if ($result->size > 0) {
                $headers['Content-Length'] = (string) $result->size;
            }

            if (is_object($result->body) && method_exists($result->body, 'read')) {
                return response()->stream(function () use ($result): void {
                    while (! $result->body->eof()) {
                        echo $result->body->read(64 * 1024);
                        if (connection_aborted()) {
                            break;
                        }
                    }
                }, $result->status, $headers);
            }

            return response($result->body, $result->status, $headers);
        }

        $sourcePath = $item->source_locator;
        abort_unless(File::isFile($sourcePath) && File::isReadable($sourcePath), 404);

        return response()->file($sourcePath, [
            'Accept-Ranges' => 'bytes',
            'Cache-Control' => 'private, no-store',
            'Content-Type' => $item->mime_type ?: 'application/octet-stream',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
