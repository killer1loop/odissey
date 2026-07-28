<?php

namespace App\Http\Controllers\Media;

use App\Http\Controllers\Controller;
use App\Models\MediaItem;
use App\Services\Media\DirectStreamConcurrencyGate;
use App\Services\Media\DirectStreamPump;
use App\Services\Media\Sources\MediaSourceRegistry;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class DirectMediaController extends Controller
{
    public function __invoke(
        Request $request,
        MediaSourceRegistry $registry,
        DirectStreamConcurrencyGate $concurrency,
        DirectStreamPump $streamPump,
        string $media,
    ): BinaryFileResponse|StreamedResponse|Response {
        $item = MediaItem::query()
            ->accessibleTo($request->user())
            ->with('source')
            ->findOrFail($media);

        abort_if(
            $item->requires_transcode
            && ! $request->attributes->get('nativeDirectAllowed', false),
            409,
        );

        if ($item->source !== null) {
            if ($request->isMethod('HEAD')) {
                $headers = array_merge(
                    $this->safeMediaHeaders($item, false),
                    [
                        'Accept-Ranges' => (
                            $item->source->capabilities['range'] ?? false
                        ) ? 'bytes' : 'none',
                    ],
                );
                if (($item->size_bytes ?? 0) > 0) {
                    $headers['Content-Length'] = (string) $item->size_bytes;
                }

                return response('', 200, $headers);
            }

            $adapter = $registry->for($item->source);
            $range = $request->header('Range');
            $start = $end = null;
            if ($range !== null) {
                if (
                    preg_match('/^bytes=(\d*)-(\d*)$/', $range, $matches) !== 1
                    || ($matches[1] === '' && $matches[2] === '')
                ) {
                    return $this->rangeNotSatisfiable($item);
                }

                $size = max(0, (int) ($item->size_bytes ?? 0));
                if ($matches[1] === '') {
                    $suffixLength = (int) $matches[2];
                    if ($suffixLength < 1 || $size < 1) {
                        return $this->rangeNotSatisfiable($item);
                    }

                    $start = max(0, $size - $suffixLength);
                    $end = $size - 1;
                } else {
                    $start = (int) $matches[1];
                    $end = $matches[2] === '' ? null : (int) $matches[2];
                }

                if (
                    ($end !== null && $end < $start)
                    || ($size > 0 && $start >= $size)
                ) {
                    return $this->rangeNotSatisfiable($item);
                }

                if ($end !== null && $size > 0) {
                    $end = min($end, $size - 1);
                }
            }

            $concurrencyLease = $concurrency->acquire(
                (string) $request->user()->getKey(),
                (string) $item->source->getKey(),
            );
            if ($concurrencyLease === null) {
                return response(
                    'Too many concurrent media streams.',
                    429,
                    [
                        'Cache-Control' => 'no-store',
                        'Content-Type' => 'text/plain; charset=UTF-8',
                        'Retry-After' => '1',
                        'X-Content-Type-Options' => 'nosniff',
                    ],
                );
            }

            $result = null;
            $streaming = false;

            try {
                try {
                    $result = $adapter->open(
                        $item->source,
                        $item->source_locator,
                        $start,
                        $end,
                    );
                } catch (RuntimeException $exception) {
                    if ($exception->getMessage() === 'source_range_invalid') {
                        return $this->rangeNotSatisfiable($item);
                    }

                    throw $exception;
                }
                $maximumBytes = min(
                    64 * 1024 * 1024 * 1024,
                    max(
                        1,
                        (int) config(
                            'odissey.remote_stream_max_bytes',
                            32 * 1024 * 1024 * 1024,
                        ),
                    ),
                );
                if (($item->size_bytes ?? 0) > 0) {
                    $maximumBytes = min(
                        $maximumBytes,
                        max(1, (int) $item->size_bytes - ($start ?? 0)),
                    );
                }
                if ($start !== null && $end !== null) {
                    $maximumBytes = min(
                        $maximumBytes,
                        $end - $start + 1,
                    );
                }

                if ($result->size > $maximumBytes) {
                    return response(
                        '',
                        502,
                        $this->safeMediaHeaders($item, false),
                    );
                }

                $headers = array_merge(
                    $this->safeMediaHeaders($item, false),
                    [
                        'Accept-Ranges' => (
                            $item->source->capabilities['range'] ?? false
                        ) ? 'bytes' : 'none',
                    ],
                );
                if ($result->status === 206 && $result->contentRange) {
                    $headers['Content-Range'] = $result->contentRange;
                }
                if ($result->size > 0) {
                    $headers['Content-Length'] = (string) $result->size;
                }

                if (
                    is_resource($result->body)
                    || (
                        is_object($result->body)
                        && method_exists($result->body, 'read')
                    )
                ) {
                    $streamMaximumBytes = $result->size > 0
                        ? min($maximumBytes, $result->size)
                        : $maximumBytes;
                    $response = response()->stream(
                        function () use (
                            $concurrencyLease,
                            $result,
                            $streamMaximumBytes,
                            $streamPump,
                        ): void {
                            try {
                                $streamPump->pump(
                                    $result->body,
                                    $concurrencyLease,
                                    $streamMaximumBytes,
                                );
                            } finally {
                                $this->closeBody($result->body);
                                $concurrencyLease->release();
                            }
                        },
                        $result->status,
                        $headers,
                    );
                    $streaming = true;

                    return $response;
                }

                if (
                    is_string($result->body)
                    && strlen($result->body) > $maximumBytes
                ) {
                    return response(
                        '',
                        502,
                        $this->safeMediaHeaders($item, false),
                    );
                }

                return response(
                    $result->body,
                    $result->status,
                    $headers,
                );
            } finally {
                if (! $streaming) {
                    if ($result !== null) {
                        $this->closeBody($result->body);
                    }

                    $concurrencyLease->release();
                }
            }
        }

        $sourcePath = $item->source_locator;
        abort_unless(File::isFile($sourcePath) && File::isReadable($sourcePath), 404);

        return response()->file($sourcePath, $this->safeMediaHeaders($item, true));
    }

    private function closeBody(mixed $body): void
    {
        try {
            if (is_object($body) && method_exists($body, 'close')) {
                $body->close();
            } elseif (is_resource($body)) {
                fclose($body);
            }
        } catch (Throwable) {
            //
        }
    }

    private function rangeNotSatisfiable(MediaItem $item): Response
    {
        $size = max(0, (int) ($item->size_bytes ?? 0));
        $headers = array_merge($this->safeMediaHeaders($item, false), [
            'Accept-Ranges' => 'bytes',
            'Content-Length' => '0',
        ]);

        if ($size > 0) {
            $headers['Content-Range'] = 'bytes */'.$size;
        }

        return response('', 416, $headers);
    }

    /**
     * @return array<string, string>
     */
    private function safeMediaHeaders(MediaItem $item, bool $ranges): array
    {
        $allowedContentTypes = [
            'audio/aac',
            'audio/flac',
            'audio/mp4',
            'audio/mpeg',
            'audio/ogg',
            'audio/opus',
            'audio/wav',
            'audio/x-wav',
            'video/mp2t',
            'video/mp4',
            'video/ogg',
            'video/quicktime',
            'video/webm',
        ];
        $contentType = strtolower(trim((string) $item->mime_type));
        $inline = in_array($contentType, $allowedContentTypes, true);
        $filename = basename((string) ($item->relative_path ?: $item->title));
        if ($filename === '' || $filename === '.' || $filename === '..') {
            $filename = 'media.bin';
        }
        $fallback = preg_replace('/[^A-Za-z0-9._-]/', '_', $filename) ?: 'media.bin';

        return [
            'Accept-Ranges' => $ranges ? 'bytes' : 'none',
            'Cache-Control' => 'private, no-store',
            'Content-Disposition' => HeaderUtils::makeDisposition(
                $inline ? HeaderUtils::DISPOSITION_INLINE : HeaderUtils::DISPOSITION_ATTACHMENT,
                $filename,
                $fallback,
            ),
            'Content-Security-Policy' => "sandbox; default-src 'none'",
            'Content-Type' => $inline ? $contentType : 'application/octet-stream',
            'Cross-Origin-Resource-Policy' => 'same-origin',
            'X-Content-Type-Options' => 'nosniff',
        ];
    }
}
