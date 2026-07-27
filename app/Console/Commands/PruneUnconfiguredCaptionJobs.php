<?php

namespace App\Console\Commands;

use App\Services\IntegrationSettings;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class PruneUnconfiguredCaptionJobs extends Command
{
    protected $signature = 'media:captions:prune-unconfigured';

    protected $description = 'Remove queued automatic caption jobs when no caption provider is configured';

    public function handle(IntegrationSettings $settings): int
    {
        if ($this->hasCaptionProvider($settings)) {
            $this->info('Caption provider configured; queued caption jobs retained.');

            return self::SUCCESS;
        }

        $deleted = DB::table('jobs')
            ->where('queue', 'media-enrichment')
            ->where('payload', 'like', '%FetchMediaCaptions%')
            ->delete();
        $this->info('Pruned '.$deleted.' unconfigured caption job(s).');

        return self::SUCCESS;
    }

    private function hasCaptionProvider(IntegrationSettings $settings): bool
    {
        return $settings->has(
            'subdl_api_key',
            config('services.subdl.api_key'),
        ) || $settings->has(
            'opensubtitles_api_key',
            config('services.opensubtitles.api_key'),
        );
    }
}
