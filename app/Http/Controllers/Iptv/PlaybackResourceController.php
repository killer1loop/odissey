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
use App\Services\Iptv\PlaybackResourceRepository;
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
    ): Response {
        $access->assertSession($request->user(), $session);
        $access->assertResource($session, $resource);

        try {
            $upstream = $fetcher->fetch($resource, $request->header('Range'));
            $contentType = $this->safeContentType($upstream->header('Content-Type'));

            if (
                $resource->resource_type === 'playlist'
                || $this->isManifestContentType($contentType)
            ) {
                $resource = $resources->promoteToPlaylist($resource);
                $body = $fetcher->bodyWithinLimit(
                    $upstream,
                    (int) config('iptv.manifest_max_bytes'),
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

            return $this->streamResponse($upstream, $contentType, $resource->resource_type);
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
        }
    }

    private function streamResponse(
        \Illuminate\Http\Client\Response $upstream,
        string $contentType,
        string $resourceType,
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
            static function () use ($body): void {
                while (! $body->eof()) {
                    echo $body->read(64 * 1024);
                    flush();
                }

                $body->close();
            },
            $upstream->status(),
            $headers,
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
}
