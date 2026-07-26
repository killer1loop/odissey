<?php

namespace App\Http\Controllers\Iptv;

use App\Http\Controllers\Controller;
use App\Models\Iptv\IptvPlaybackResource;
use App\Models\Iptv\IptvPlaybackSession;
use App\Services\Iptv\Exceptions\SanitizedIptvException;
use App\Services\Iptv\Exceptions\UpstreamResponseException;
use App\Services\Iptv\HlsPlaylistRewriter;
use App\Services\Iptv\IptvProxyFetcher;
use App\Services\Iptv\PlaybackAccess;
use App\Services\Iptv\PlaybackAttemptRecorder;
use App\Services\Iptv\PlaybackConcurrencyGate;
use App\Services\Iptv\PlaybackConcurrencyLease;
use App\Services\Iptv\PlaybackResourceRepository;
use App\Services\Iptv\PlaybackStreamPump;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class PlaybackResourceController extends Controller
{
    public function __invoke(
        Request $request,
        IptvPlaybackSession $session,
        IptvPlaybackResource $resource,
        PlaybackAccess $access,
        IptvProxyFetcher $fetcher,
        HlsPlaylistRewriter $rewriter,
        PlaybackAttemptRecorder $attempts,
        PlaybackResourceRepository $resources,
        PlaybackConcurrencyGate $concurrency,
        PlaybackStreamPump $streamPump,
    ): Response {
        $access->assertSession($request->user(), $session);
        $access->assertResource($session, $resource);
        $concurrencyLease = $concurrency->acquire($session);

        if ($concurrencyLease === null) {
            return response('Too many concurrent playback requests.', 429, [
                'Cache-Control' => 'no-store',
                'Content-Type' => 'text/plain; charset=UTF-8',
                'Retry-After' => '1',
            ]);
        }

        $upstream = null;
        $streaming = false;
        try {
            $upstream = $fetcher->fetch($resource, $request->header('Range'));
            $contentType = $this->safeContentType($upstream->header('Content-Type'));
            $prefix = '';
            $isPlaylist = (
                $resource->resource_type === 'playlist'
                || $this->isManifestContentType($contentType)
            );

            if (
                ! $isPlaylist
                && $resource->resource_type === 'resource'
                && $this->isGenericContentType($contentType)
            ) {
                $prefix = $this->readPrefix($upstream, 64);
                $isPlaylist = str_starts_with(ltrim($prefix, " \t"), '#EXTM3U');
            }

            if ($isPlaylist) {
                $resource = $resources->promoteToPlaylist($resource);
                $body = $fetcher->bodyWithinLimit(
                    $upstream,
                    (int) config('iptv.manifest_max_bytes'),
                    $prefix,
                );

                $access->touch($session, $resource);

                return response(
                    $rewriter->rewrite($body, $resource),
                    200,
                    [
                        'Cache-Control' => 'private, no-store',
                        'Content-Type' => 'application/vnd.apple.mpegurl',
                        'X-Content-Type-Options' => 'nosniff',
                    ],
                );
            }

            $access->touch($session, $resource);
            $resource->forceFill(['content_type' => $contentType])->save();
            $this->capStreamLifetime($session, $concurrencyLease);

            $response = $this->streamResponse(
                $upstream,
                $contentType,
                $resource->resource_type,
                $prefix,
                $concurrencyLease,
                $streamPump,
            );
            $streaming = true;

            return $response;
        } catch (SanitizedIptvException $exception) {
            $attempts->record(
                $session,
                'failed',
                $exception instanceof UpstreamResponseException
                    ? $exception->upstreamStatus
                    : null,
                $exception->errorCode,
            );

            return response('Stream resource temporarily unavailable.', $exception->httpStatus(), [
                'Cache-Control' => 'no-store',
                'Content-Type' => 'text/plain; charset=UTF-8',
            ]);
        } catch (Throwable) {
            $attempts->record($session, 'failed', errorCode: 'internal_proxy_error');

            return response('Stream resource temporarily unavailable.', 502, [
                'Cache-Control' => 'no-store',
                'Content-Type' => 'text/plain; charset=UTF-8',
            ]);
        } finally {
            if (! $streaming) {
                if ($upstream !== null) {
                    $upstream->toPsrResponse()->getBody()->close();
                }

                $concurrencyLease->release();
            }
        }
    }

    private function streamResponse(
        \Illuminate\Http\Client\Response $upstream,
        string $contentType,
        string $resourceType,
        string $prefix = '',
        ?PlaybackConcurrencyLease $concurrencyLease = null,
        ?PlaybackStreamPump $streamPump = null,
    ): StreamedResponse {
        $body = $upstream->toPsrResponse()->getBody();
        $headers = [
            'Cache-Control' => $resourceType === 'key'
                ? 'private, no-store'
                : 'private, max-age=10',
            'Content-Type' => $contentType,
            'X-Content-Type-Options' => 'nosniff',
        ];

        $contentLength = $upstream->header('Content-Length');
        $contentRange = $upstream->header('Content-Range');

        if (is_string($contentLength) && ctype_digit($contentLength)) {
            $headers['Content-Length'] = $contentLength;
        }

        if (
            is_string($contentRange)
            && preg_match('/^bytes \d+-\d+\/(?:\d+|\*)$/', $contentRange) === 1
        ) {
            $headers['Content-Range'] = $contentRange;
            $headers['Accept-Ranges'] = 'bytes';
        }

        return response()->stream(
            static function () use (
                $body,
                $concurrencyLease,
                $prefix,
                $streamPump,
            ): void {
                try {
                    if (
                        $concurrencyLease !== null
                        && $streamPump !== null
                    ) {
                        $streamPump->pump(
                            $body,
                            $concurrencyLease,
                            $prefix,
                        );
                    }
                } finally {
                    $body->close();
                    $concurrencyLease?->release();
                }
            },
            $upstream->status(),
            $headers,
        );
    }

    private function capStreamLifetime(
        IptvPlaybackSession $session,
        PlaybackConcurrencyLease $lease,
    ): void {
        $configuredMaximum = min(
            300,
            max(
                1,
                (int) config('iptv.playback_stream_max_seconds', 60),
            ),
        );
        $sessionRemaining = max(
            0,
            $session->expires_at->getTimestamp() - now()->getTimestamp(),
        );

        // Finish before the database lease expires. The cache-lock deadline
        // remains an independent upper bound inside the lease itself.
        $lease->capLifetime(
            max(0, min($configuredMaximum, $sessionRemaining) - 1),
        );
    }

    private function safeContentType(?string $contentType): string
    {
        if (
            ! is_string($contentType)
            || preg_match('/^[a-z0-9.+-]+\/[a-z0-9.+-]+(?:;\s*charset=[a-z0-9._-]+)?$/i', $contentType) !== 1
        ) {
            return 'application/octet-stream';
        }

        return $contentType;
    }

    private function isManifestContentType(string $contentType): bool
    {
        return in_array(strtolower(strtok($contentType, ';') ?: $contentType), [
            'application/vnd.apple.mpegurl',
            'application/x-mpegurl',
            'audio/mpegurl',
            'audio/x-mpegurl',
        ], true);
    }

    private function isGenericContentType(string $contentType): bool
    {
        return in_array(strtolower(strtok($contentType, ';') ?: $contentType), [
            'application/octet-stream',
            'text/plain',
        ], true);
    }

    private function readPrefix(
        \Illuminate\Http\Client\Response $upstream,
        int $length,
    ): string {
        $stream = $upstream->toPsrResponse()->getBody();
        $prefix = '';

        while (strlen($prefix) < $length && ! $stream->eof()) {
            $chunk = $stream->read($length - strlen($prefix));

            if ($chunk === '') {
                break;
            }

            $prefix .= $chunk;
        }

        return $prefix;
    }
}
