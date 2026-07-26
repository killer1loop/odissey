<?php

namespace App\Http\Controllers\Iptv;

use App\Http\Controllers\Controller;
use App\Models\Iptv\IptvPlaybackSession;
use App\Services\Iptv\Exceptions\SanitizedIptvException;
use App\Services\Iptv\Exceptions\UpstreamResponseException;
use App\Services\Iptv\HlsPlaylistRewriter;
use App\Services\Iptv\IptvProxyFetcher;
use App\Services\Iptv\PlaybackAccess;
use App\Services\Iptv\PlaybackAttemptRecorder;
use App\Services\Iptv\PlaybackConcurrencyGate;
use App\Services\Iptv\PlaybackSessionManager;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Throwable;

class PlaybackManifestController extends Controller
{
    public function __invoke(
        Request $request,
        IptvPlaybackSession $session,
        PlaybackAccess $access,
        PlaybackSessionManager $sessions,
        IptvProxyFetcher $fetcher,
        HlsPlaylistRewriter $rewriter,
        PlaybackAttemptRecorder $attempts,
        PlaybackConcurrencyGate $concurrency,
    ): Response {
        $access->assertSession($request->user(), $session);
        $root = $sessions->rootResource($session);
        $concurrencyLease = $concurrency->acquire($session);

        if ($concurrencyLease === null) {
            return response('Too many concurrent playback requests.', 429, [
                'Cache-Control' => 'no-store',
                'Content-Type' => 'text/plain; charset=UTF-8',
                'Retry-After' => '1',
            ]);
        }

        $upstream = null;
        try {
            $upstream = $fetcher->fetch($root);
            $body = $fetcher->bodyWithinLimit(
                $upstream,
                (int) config('iptv.manifest_max_bytes'),
            );

            $manifest = $rewriter->rewrite($body, $root);
            $root->forceFill([
                'content_type' => 'application/vnd.apple.mpegurl',
            ])->save();
            $access->touch($session, $root);
            $attempts->record($session, 'started', $upstream->status());

            return response($manifest, 200, [
                'Cache-Control' => 'private, no-store',
                'Content-Type' => 'application/vnd.apple.mpegurl',
                'X-Content-Type-Options' => 'nosniff',
            ]);
        } catch (SanitizedIptvException $exception) {
            $attempts->record(
                $session,
                'failed',
                $exception instanceof UpstreamResponseException
                    ? $exception->upstreamStatus
                    : null,
                $exception->errorCode,
            );

            return response('Live stream temporarily unavailable.', $exception->httpStatus(), [
                'Cache-Control' => 'no-store',
                'Content-Type' => 'text/plain; charset=UTF-8',
            ]);
        } catch (Throwable) {
            $attempts->record($session, 'failed', errorCode: 'internal_proxy_error');

            return response('Live stream temporarily unavailable.', 502, [
                'Cache-Control' => 'no-store',
                'Content-Type' => 'text/plain; charset=UTF-8',
            ]);
        } finally {
            if ($upstream !== null) {
                $upstream->toPsrResponse()->getBody()->close();
            }

            $concurrencyLease->release();
        }
    }
}
