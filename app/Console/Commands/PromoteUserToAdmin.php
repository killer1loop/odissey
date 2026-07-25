<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PromoteUserToAdmin extends Command
{
    protected $signature = 'odissey:user:promote-admin
        {email : Exact email address of the existing user to promote}
        {--force : Required in production and skips interactive confirmation}';

    protected $description = 'Explicitly promote one existing user to an active Odissey administrator';

    public function handle(): int
    {
        $email = Str::lower(trim((string) $this->argument('email')));

        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $this->error('A valid existing user email address is required.');

            return self::FAILURE;
        }

        $users = User::query()
            ->whereRaw('LOWER(email) = ?', [$email])
            ->limit(2)
            ->get();

        if ($users->count() !== 1) {
            $this->error(
                $users->isEmpty()
                    ? 'No user exists with that email address.'
                    : 'More than one user matches that email address; resolve the duplicate first.',
            );

            return self::FAILURE;
        }

        $user = $users->sole();

        if (app()->isProduction() && ! $this->option('force')) {
            $this->error('--force is required to promote an administrator in production.');

            return self::FAILURE;
        }

        if (
            ! $this->option('force')
            && ! $this->confirm("Promote {$user->email} to administrator?")
        ) {
            $this->info('Administrator promotion cancelled.');

            return self::SUCCESS;
        }

        DB::transaction(function () use ($user): void {
            $now = now();

            $user->forceFill([
                'is_admin' => true,
                'is_active' => true,
                'disabled_at' => null,
            ])->save();

            $updated = DB::table('installation_states')
                ->where('key', 'initial_setup')
                ->update([
                    'completed_at' => $now,
                    'updated_at' => $now,
                ]);

            if ($updated === 0) {
                DB::table('installation_states')->insert([
                    'key' => 'initial_setup',
                    'completed_at' => $now,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        });

        $this->info("{$user->email} is now an active administrator.");

        return self::SUCCESS;
    }
}
