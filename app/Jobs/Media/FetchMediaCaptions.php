<?php

namespace App\Jobs\Media;

use App\Models\MediaItem;
use App\Models\MediaSubtitle;
use App\Services\IntegrationSettings;
use App\Services\Media\Captions\CaptionStorage;
use App\Services\Media\Captions\OpenSubtitlesCaptionProvider;
use App\Services\Media\Captions\SubdlCaptionProvider;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class FetchMediaCaptions implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $timeout = 180;

    public int $tries = 2;

    public int $uniqueFor = 600;

    public function __construct(public readonly string $mediaItemId) {}

    public function uniqueId(): string
    {
        return $this->mediaItemId;
    }

    public function handle(CaptionStorage $storage, SubdlCaptionProvider $subdl, OpenSubtitlesCaptionProvider $openSubtitles): void
    {
        $item = MediaItem::find($this->mediaItemId);
        if (! $item || $item->media_kind !== 'video') {
            return;
        }
        $languageSetting = app(IntegrationSettings::class)->get('caption_languages', implode(',', config('odissey.caption_languages')));
        $languages = array_values(array_filter(explode(',', $languageSetting), fn ($language) => preg_match('/^[a-z]{2,3}$/', $language)));
        foreach ([$subdl, $openSubtitles] as $provider) {
            try {
                foreach ($provider->search($item, $languages) as $candidate) {
                    if (MediaSubtitle::where(['media_item_id' => $item->id, 'provider' => $candidate->provider, 'external_id' => $candidate->externalId])->exists()) {
                        continue;
                    }
                    $host = strtolower((string) parse_url($candidate->downloadUrl, PHP_URL_HOST));
                    $allowed = $candidate->provider === 'subdl'
                        ? ($host === 'dl.subdl.com')
                        : ($host === 'opensubtitles.com' || str_ends_with($host, '.opensubtitles.com') || str_ends_with($host, '.opensubtitles.org'));
                    if (! $allowed) {
                        continue;
                    }
                    $response = Http::withHeaders($candidate->headers)->timeout(30)->maxRedirects(2)->get($candidate->downloadUrl);
                    if (! $response->successful()) {
                        continue;
                    }
                    $path = $storage->store($item, $candidate, $response->body());
                    MediaSubtitle::create([
                        'media_item_id' => $item->id, 'provider' => $candidate->provider,
                        'external_id' => $candidate->externalId, 'language' => $candidate->language,
                        'label' => $candidate->label, 'path' => $path,
                        'hearing_impaired' => $candidate->hearingImpaired,
                    ]);
                }
            } catch (Throwable $exception) {
                Log::notice('Caption provider request failed safely.', ['media_item_id' => $item->id, 'provider' => $provider::class, 'exception' => $exception::class]);
            }
        }
    }
}
