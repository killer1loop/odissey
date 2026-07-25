<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Media\MediaFixtureGenerator;
use Illuminate\Console\Command;
use Throwable;

class GenerateMediaE2eFixtures extends Command
{
    protected $description = 'Generate short transient video fixtures for an explicit E2E media test';

    protected $signature = 'media:e2e:generate
                            {user : User ID or email that will own the fixtures}
                            {--duration=8 : Fixture duration in seconds (2-30)}
                            {--force : Permit fixture generation in production}';

    public function handle(MediaFixtureGenerator $generator): int
    {
        if (app()->isProduction() && ! $this->option('force')) {
            $this->error('Use --force to generate transient E2E media in production.');

            return self::FAILURE;
        }

        $duration = filter_var($this->option('duration'), FILTER_VALIDATE_INT);

        if ($duration === false || $duration < 2 || $duration > 30) {
            $this->error('Duration must be an integer between 2 and 30 seconds.');

            return self::INVALID;
        }

        $identifier = (string) $this->argument('user');
        $user = User::query()
            ->whereKey($identifier)
            ->orWhere('email', $identifier)
            ->first();

        if ($user === null) {
            $this->error('The requested fixture owner does not exist.');

            return self::FAILURE;
        }

        try {
            $items = $generator->generate($user, $duration);
        } catch (Throwable) {
            $this->error('Fixture generation failed. Confirm that FFmpeg is available and try again.');

            return self::FAILURE;
        }

        $this->info('Generated transient E2E media:');
        $items->each(fn ($item) => $this->line(sprintf('  %s  %s', $item->getKey(), $item->title)));

        return self::SUCCESS;
    }
}
