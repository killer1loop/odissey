<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PromoteAdminCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_explicit_existing_user_can_be_promoted_without_promoting_other_users(): void
    {
        $target = User::factory()->create([
            'email' => 'legacy-admin@example.test',
            'is_active' => false,
            'disabled_at' => now(),
        ]);
        $other = User::factory()->create([
            'email' => 'viewer@example.test',
        ]);

        DB::table('installation_states')
            ->where('key', 'initial_setup')
            ->update(['completed_at' => null]);

        $this->artisan('odissey:user:promote-admin', [
            'email' => 'LEGACY-ADMIN@EXAMPLE.TEST',
            '--force' => true,
        ])
            ->expectsOutput('legacy-admin@example.test is now an active administrator.')
            ->assertSuccessful();

        $target->refresh();
        $other->refresh();

        $this->assertTrue($target->is_admin);
        $this->assertTrue($target->is_active);
        $this->assertNull($target->disabled_at);
        $this->assertFalse($other->is_admin);
        $this->assertNotNull(
            DB::table('installation_states')
                ->where('key', 'initial_setup')
                ->value('completed_at'),
        );
    }

    public function test_production_promotion_requires_force(): void
    {
        $user = User::factory()->create([
            'email' => 'legacy-admin@example.test',
        ]);
        $this->app['env'] = 'production';

        $this->artisan('odissey:user:promote-admin', [
            'email' => $user->email,
        ])
            ->expectsOutput('--force is required to promote an administrator in production.')
            ->assertFailed();

        $this->assertFalse($user->fresh()->is_admin);

        $this->artisan('odissey:user:promote-admin', [
            'email' => $user->email,
            '--force' => true,
        ])->assertSuccessful();

        $this->assertTrue($user->fresh()->is_admin);
        $this->app['env'] = 'testing';
    }

    public function test_a_missing_user_is_not_silently_replaced_with_another_account(): void
    {
        $existing = User::factory()->create();

        $this->artisan('odissey:user:promote-admin', [
            'email' => 'missing@example.test',
            '--force' => true,
        ])
            ->expectsOutput('No user exists with that email address.')
            ->assertFailed();

        $this->assertFalse($existing->fresh()->is_admin);
    }
}
