<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\MediaItem;
use App\Models\TranscodeSession;
use App\Services\Media\TranscodeStorage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Symfony\Component\HttpFoundation\Response;

class NativeHlsMasterController extends Controller
{
    public function __invoke(
        Request $request,
        TranscodeStorage $storage,
        string $media,
        string $session,
    ): Response {
        $item = MediaItem::query()
            ->accessibleTo($request->user())
            ->with('subtitles')
            ->findOrFail($media);
        $transcode = TranscodeSession::query()
            ->whereBelongsTo($request->user())
            ->whereBelongsTo($item, 'mediaItem')
            ->findOrFail($session);
        abort_unless($transcode->isAvailable(), 404);
        abort_unless(File::isFile($storage->manifestPath($transcode)), 404);

        $grant = (string) $request->attributes->get(
            'nativePlaybackGrantId',
        );
        $token = (string) $request->attributes->get(
            'nativePlaybackGrantToken',
        );
        $videoUrl = route('api.v1.playback.media.transcode.manifest', [
            $grant,
            $token,
            $item,
            $transcode,
        ]);
        $subtitles = $this->subtitles(
            $transcode,
            $item,
            $grant,
            $token,
        );
        abort_if($subtitles === [], 404);

        $subtitleLines = collect($subtitles)
            ->map(function (array $subtitle): string {
                $language = $this->attribute($subtitle['language']);
                $label = $this->attribute(
                    $subtitle['label'] ?: 'Subtitles',
                );
                $subtitleUrl = $this->attribute($subtitle['url']);

                return '#EXT-X-MEDIA:TYPE=SUBTITLES,GROUP-ID="subs",NAME="'
                    .$label.'",DEFAULT='.($subtitle['selected'] ? 'YES' : 'NO')
                    .',AUTOSELECT=YES,FORCED=NO'
                    .($language !== '' ? ',LANGUAGE="'.$language.'"' : '')
                    .',URI="'.$subtitleUrl.'"';
            })
            ->implode("\n");
        $bandwidth = max(
            1_000_000,
            (int) ($item->metadata['technical']['bit_rate'] ?? 10_000_000),
        );
        $manifest = "#EXTM3U\n#EXT-X-VERSION:7\n"
            .$subtitleLines."\n"
            .'#EXT-X-STREAM-INF:BANDWIDTH='.$bandwidth
            .',SUBTITLES="subs"'."\n"
            .$videoUrl."\n";

        return response($manifest, 200, [
            'Cache-Control' => 'private, no-store',
            'Content-Type' => 'application/vnd.apple.mpegurl',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * @return list<array{language: string, label: string, url: string, selected: bool}>
     */
    private function subtitles(
        TranscodeSession $session,
        MediaItem $item,
        string $grant,
        string $token,
    ): array {
        $subtitles = [];
        $tracks = $item->metadata['technical']['subtitle_tracks'] ?? [];
        foreach (
            array_slice(is_array($tracks) ? $tracks : [], 0, 32) as $index => $track
        ) {
            if (! is_array($track)) {
                continue;
            }
            $subtitles[] = [
                'language' => (string) ($track['language'] ?? ''),
                'label' => (string) ($track['title'] ?? ''),
                'url' => route(
                    'api.v1.playback.media.subtitles.embedded',
                    [$grant, $token, $item, $index],
                ),
                'selected' => $session->subtitle_track === $index,
            ];
        }
        foreach ($item->subtitles->take(64) as $caption) {
            $subtitles[] = [
                'language' => (string) $caption->language,
                'label' => (string) $caption->label,
                'url' => route(
                    'api.v1.playback.media.subtitles.caption',
                    [$grant, $token, $item, $caption],
                ),
                'selected' => $session->media_subtitle_id === $caption->id,
            ];
        }

        return collect($subtitles)->contains('selected', true)
            ? $subtitles
            : [];
    }

    private function attribute(string $value): string
    {
        return str_replace(
            ["\r", "\n", '"'],
            ['', '', "'"],
            mb_substr($value, 0, 512),
        );
    }
}
