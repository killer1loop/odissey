<?php

namespace App\Http\Controllers\Media;

use App\Http\Controllers\Controller;
use App\Models\TranscodeSession;
use App\Services\Media\DirectStreamLease;
use App\Services\Media\DirectStreamPump;
use App\Services\Media\Sources\MediaSourceRegistry;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class TranscodeSourceController extends Controller
{
    public function __invoke(
        Request $request,
        MediaSourceRegistry $registry,
        DirectStreamPump $streamPump,
        string $session,
    ): BinaryFileResponse|StreamedResponse|Response {
        abort_unless(
            in_array(
                (string) $request->server('REMOTE_ADDR'),
                ['127.0.0.1', '::1'],
                true,
            ),
            404,
        );

        $session = TranscodeSession::query()
            ->whereIn('status', [
                TranscodeSession::STATUS_PENDING,
                TranscodeSession::STATUS_PROCESSING,
                TranscodeSession::STATUS_READY,
            ])
            ->with('mediaItem.source')
            ->findOrFail($session);
        $item = $session->mediaItem;

        if ($item->source === null) {
            abort_unless(
                File::isFile($item->source_locator)
                    && File::isReadable($item->source_locator),
                404,
            );

            return response()->file($item->source_locator, $this->headers());
        }

        try {
            [$start, $end] = $this->range(
                $request,
                (int) ($item->size_bytes ?? 0),
            );
        } catch (RuntimeException) {
            $headers = $this->headers();
            $headers['Content-Length'] = '0';
            if (($item->size_bytes ?? 0) > 0) {
                $headers['Content-Range'] = 'bytes */'.$item->size_bytes;
            }

            return response('', 416, $headers);
        }
        $result = $registry
            ->for($item->source)
            ->open($item->source, $item->source_locator, $start, $end);
        $maximumBytes = min(
            16 * 1024 * 1024 * 1024,
            max(
                1,
                (int) config(
                    'odissey.remote_transcode_max_source_bytes',
                    3 * 1024 * 1024 * 1024,
                ),
            ),
        );

        if ($start !== null && $end !== null) {
            $maximumBytes = min($maximumBytes, $end - $start + 1);
        }
        if ($result->size > 0) {
            $maximumBytes = min($maximumBytes, $result->size);
        }

        $headers = $this->headers();
        $headers['Accept-Ranges'] = (
            $item->source->capabilities['range'] ?? false
        ) ? 'bytes' : 'none';
        if ($result->contentRange !== null) {
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
            return response()->stream(
                function () use ($result, $maximumBytes, $streamPump): void {
                    $lease = new DirectStreamLease(
                        [],
                        min(
                            86400,
                            max(
                                60,
                                (int) config(
                                    'odissey.transcode_source_max_seconds',
                                    21600,
                                ),
                            ),
                        ),
                    );

                    try {
                        $streamPump->pump(
                            $result->body,
                            $lease,
                            $maximumBytes,
                        );
                    } finally {
                        $lease->release();
                        $this->closeBody($result->body);
                    }
                },
                $result->status,
                $headers,
            );
        }

        $body = is_string($result->body)
            ? $result->body
            : (string) $result->body;
        $this->closeBody($result->body);

        if (strlen($body) > $maximumBytes) {
            return response('', 502, $this->headers());
        }

        return response($body, $result->status, $headers);
    }

    /**
     * @return array{int|null, int|null}
     */
    private function range(Request $request, int $size): array
    {
        $range = $request->header('Range');

        if ($range === null) {
            return [null, null];
        }

        if (
            preg_match('/^bytes=(\d*)-(\d*)$/', $range, $matches) !== 1
            || ($matches[1] === '' && $matches[2] === '')
        ) {
            throw new RuntimeException('source_range_invalid');
        }

        if ($matches[1] === '') {
            $suffixLength = (int) $matches[2];
            if ($suffixLength < 1 || $size < 1) {
                throw new RuntimeException('source_range_invalid');
            }

            return [max(0, $size - $suffixLength), $size - 1];
        }

        $start = (int) $matches[1];
        $end = $matches[2] === '' ? null : (int) $matches[2];
        if (
            ($end !== null && $end < $start)
            || ($size > 0 && $start >= $size)
        ) {
            throw new RuntimeException('source_range_invalid');
        }

        if ($end !== null && $size > 0) {
            $end = min($end, $size - 1);
        }

        return [$start, $end];
    }

    /**
     * @return array<string, string>
     */
    private function headers(): array
    {
        return [
            'Accept-Ranges' => 'bytes',
            'Cache-Control' => 'private, no-store',
            'Content-Type' => 'application/octet-stream',
            'X-Content-Type-Options' => 'nosniff',
        ];
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
}
