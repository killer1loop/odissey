<?php

namespace App\Console\Commands;

use App\Services\Media\MediaFixtureGenerator;
use Illuminate\Console\Command;
use Throwable;

class CleanMediaE2eFixtures extends Command
{
    protected $description = 'Remove transient E2E media fixtures and their catalog records';

    protected $signature = 'media:e2e:clean
                            {--force : Permit fixture cleanup in production}';

    public function handle(MediaFixtureGenerator $generator): int
    {
        if (app()->isProduction() && ! $this->option('force')) {
            $this->error('Use --force to clean transient E2E media in production.');

            return self::FAILURE;
        }

        try {
            $deleted = $generator->clean();
        } catch (Throwable) {
            $this->error('Fixture cleanup failed.');

            return self::FAILURE;
        }

        $this->info(sprintf('Removed %d transient E2E media record(s).', $deleted));

        return self::SUCCESS;
    }
}
