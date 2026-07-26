<?php

namespace App\Http\Controllers\Iptv;

use App\Http\Controllers\Controller;
use App\Models\Iptv\Channel;
use App\Services\Media\BoundedMediaDownloader;
use Illuminate\Http\Response;
use Throwable;

class ChannelIconController extends Controller
{
    private const MAX_ICON_BYTES = 2 * 1024 * 1024;

    public function __invoke(
        Channel $channel,
        BoundedMediaDownloader $images,
    ): Response {
        $channel->loadMissing(['provider', 'group']);
        abort_unless(
            $channel->is_active
            && $channel->provider->enabled
            && ($channel->group === null || $channel->group->is_active),
            404,
        );

        try {
            $url = $channel->stream_icon;

            if (
                $channel->logo_source !== 'iptv-org'
                || ! is_string($url)
                || trim($url) === ''
            ) {
                abort(404);
            }

            $download = $images->download(
                url: $url,
                maxBytes: self::MAX_ICON_BYTES,
                allowedHost: static fn (string $host): bool => $host !== '',
                maxRedirects: 2,
                timeoutSeconds: 10,
            );
            $body = $download['body'];
        } catch (Throwable) {
            abort(404);
        }

        $image = $body === '' ? false : @getimagesizefromstring($body);
        $contentType = match (is_array($image) ? ($image[2] ?? null) : null) {
            IMAGETYPE_JPEG => 'image/jpeg',
            IMAGETYPE_PNG => 'image/png',
            IMAGETYPE_GIF => 'image/gif',
            IMAGETYPE_WEBP => 'image/webp',
            default => null,
        };
        abort_if($contentType === null, 404);

        return response($body, 200, [
            'Cache-Control' => 'private, max-age=86400, stale-while-revalidate=3600',
            'Content-Length' => (string) strlen($body),
            'Content-Type' => $contentType,
            'Cross-Origin-Resource-Policy' => 'same-origin',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
