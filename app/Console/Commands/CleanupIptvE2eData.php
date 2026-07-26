<?php

namespace App\Console\Commands;

use App\Models\Iptv\IptvProvider;
use App\Models\User;
use App\Services\Auth\SessionRevoker;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CleanupIptvE2eData extends Command
{
    protected $signature = 'iptv:e2e:clean
        {--provider= : Exact provider name; without it, only providers explicitly tagged e2e are selected}
        {--user= : Exact email of a clearly E2E-tagged non-admin user to disable}
        {--force : Required in production and skips interactive confirmation}';

    protected $description = 'Remove explicitly tagged IPTV E2E state and optionally disable its exact test user';

    public function handle(SessionRevoker $sessions): int
    {
        $providerName = trim((string) $this->option('provider'));
        $userEmail = Str::lower(trim((string) $this->option('user')));
        $providers = IptvProvider::query()
            ->when(
                $providerName !== '',
                fn ($query) => $query->where('name', $providerName),
            )
            ->get()
            ->when(
                $providerName === '',
                fn ($items) => $items->filter(
                    fn (IptvProvider $provider) => (
                        ($provider->config['e2e'] ?? false) === true
                        || str_starts_with($provider->name, 'E2E ')
                        || str_ends_with($provider->name, ' E2E')
                        || str_ends_with($provider->name, ' [E2E]')
                    ),
                ),
            );

        $user = null;

        if ($userEmail !== '') {
            if (filter_var($userEmail, FILTER_VALIDATE_EMAIL) === false) {
                $this->error('--user must be a valid exact email address.');

                return self::FAILURE;
            }

            $user = User::query()
                ->whereRaw('LOWER(email) = ?', [$userEmail])
                ->first();

            if ($user === null) {
                $this->error('No user exists with the exact --user email address.');

                return self::FAILURE;
            }

            if ($user->is_admin || ! $this->isE2eTaggedUser($user)) {
                $this->error(
                    'The selected user must be a non-admin explicitly tagged for E2E testing.',
                );

                return self::FAILURE;
            }
        }

        if ($providers->isEmpty() && $user === null) {
            $this->warn('No matching E2E-tagged provider was found. No data was changed.');

            return self::SUCCESS;
        }

        if (app()->isProduction() && ! $this->option('force')) {
            $this->error('--force is required to clean E2E data in production.');

            return self::FAILURE;
        }

        if (
            ! $this->option('force')
            && ! $this->confirm(
                $user === null
                    ? 'Delete the selected IPTV E2E data?'
                    : "Delete the selected IPTV E2E data and disable {$user->email}?",
            )
        ) {
            $this->info('Cleanup cancelled.');

            return self::SUCCESS;
        }

        $providerIds = $providers->pluck('id');
        [$providerCount, $userCount] = DB::transaction(function () use ($providerIds, $user): array {
            $providerCount = IptvProvider::query()->whereKey($providerIds)->delete();

            if ($user !== null) {
                $user->forceFill([
                    'is_active' => false,
                    'disabled_at' => now(),
                    'remember_token' => null,
                ])->save();
            }

            return [$providerCount, $user === null ? 0 : 1];
        });

        if ($user !== null) {
            $sessions->revokeDatabaseSessions($user);
        }

        $this->info(sprintf(
            'IPTV E2E cleanup complete: %d provider(s) and %d test user(s) disabled.',
            $providerCount,
            $userCount,
        ));

        return self::SUCCESS;
    }

    private function isE2eTaggedUser(User $user): bool
    {
        return ($user->preferences['e2e'] ?? false) === true
            || str_starts_with($user->name, 'E2E ')
            || str_ends_with($user->name, ' E2E')
            || str_ends_with($user->name, ' [E2E]');
    }
}
