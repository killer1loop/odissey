<?php

namespace App\Http\Middleware;

use App\Http\ApiProblem;
use App\Models\Iptv\IptvPlaybackSession;
use App\Services\Api\PlaybackGrantService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateNativePlaybackGrant
{
    public function __construct(
        private readonly PlaybackGrantService $grants,
    ) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $grantId = (string) $request->route('grant');
        $token = (string) $request->route('grantToken');
        $grant = $this->grants->verify($grantId, $token);

        if ($grant === null || ! $this->matchesResource($request, $grant)) {
            return ApiProblem::authentication(
                'The playback authorization is invalid or expired.',
            );
        }

        Auth::setUser($grant->user);
        $request->setUserResolver(static fn () => $grant->user);
        $request->attributes->set('nativePlaybackGrant', $grant);
        $request->attributes->set(
            'nativeDirectAllowed',
            $grant->delivery_mode === 'direct',
        );
        $request->attributes->set(
            'nativePlaybackGrantId',
            (string) $grant->getKey(),
        );
        $request->attributes->set('nativePlaybackGrantToken', $token);

        // Existing streaming controllers accept only their original web route
        // parameters. Keep grant credentials in request attributes so Laravel
        // does not positionally inject them into controller arguments.
        $request->route()->forgetParameter('grant');
        $request->route()->forgetParameter('grantToken');

        return $next($request);
    }

    private function matchesResource(Request $request, object $grant): bool
    {
        $media = $request->route('media');
        if ($media !== null) {
            $mediaId = is_object($media) && method_exists($media, 'getKey')
                ? (string) $media->getKey()
                : (string) $media;

            if (
                $grant->resource_type !== 'media'
                || ! hash_equals($grant->resource_id, $mediaId)
            ) {
                return false;
            }

            $transcode = $request->route('session');

            return $transcode === null
                || (
                    is_string($grant->playback_reference)
                    && hash_equals(
                        $grant->playback_reference,
                        is_object($transcode)
                            && method_exists($transcode, 'getKey')
                                ? (string) $transcode->getKey()
                                : (string) $transcode,
                    )
                );
        }

        $session = $request->route('session');
        if ($session !== null) {
            $playbackSession = $session instanceof IptvPlaybackSession
                ? $session
                : IptvPlaybackSession::query()->find((string) $session);

            return $playbackSession !== null
                && $grant->resource_type === 'channel'
                && hash_equals(
                    $grant->resource_id,
                    (string) $playbackSession->channel_id,
                )
                && is_string($grant->playback_reference)
                && hash_equals(
                    $grant->playback_reference,
                    (string) $playbackSession->getKey(),
                );
        }

        return false;
    }
}
