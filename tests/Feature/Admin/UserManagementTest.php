<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class UserManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_routes_require_authentication_and_admin_access(): void
    {
        $this->get('/admin/users')->assertRedirect(route('login'));

        $user = User::factory()->create([
            'is_admin' => false,
            'is_active' => true,
        ]);

        $this->actingAs($user)
            ->get('/admin/users')
            ->assertForbidden();
    }

    public function test_an_admin_can_create_an_active_non_admin_user(): void
    {
        $admin = $this->createAdmin();

        $this->actingAs($admin)
            ->post('/admin/users', [
                'name' => 'Playback Tester',
                'email' => 'TESTER@EXAMPLE.TEST',
                'password' => 'VeryStrong!123',
                'password_confirmation' => 'VeryStrong!123',
            ])
            ->assertRedirect(route('admin.users.index'));

        $user = User::query()->where('email', 'tester@example.test')->sole();

        $this->assertFalse($user->is_admin);
        $this->assertTrue($user->is_active);
        $this->assertTrue(Hash::check('VeryStrong!123', $user->password));
    }

    public function test_an_admin_can_disable_a_non_admin_and_the_user_can_no_longer_log_in(): void
    {
        $admin = $this->createAdmin();
        $user = User::factory()->create([
            'email' => 'viewer@example.test',
            'password' => 'VeryStrong!123',
            'is_admin' => false,
            'is_active' => true,
        ]);

        $this->actingAs($admin)
            ->patch(route('admin.users.disable', $user))
            ->assertRedirect(route('admin.users.index'));

        $user->refresh();
        $this->assertFalse($user->is_active);
        $this->assertNotNull($user->disabled_at);
        $this->assertNull($user->remember_token);

        $this->post('/logout');
        $this->post('/login', [
            'email' => 'viewer@example.test',
            'password' => 'VeryStrong!123',
        ])->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_a_non_admin_cannot_disable_another_user(): void
    {
        $user = User::factory()->create([
            'is_admin' => false,
            'is_active' => true,
        ]);
        $otherUser = User::factory()->create([
            'is_admin' => false,
            'is_active' => true,
        ]);

        $this->actingAs($user)
            ->patch(route('admin.users.disable', $otherUser))
            ->assertForbidden();

        $this->assertTrue($otherUser->fresh()->is_active);
    }

    public function test_an_admin_account_cannot_be_disabled_through_user_management(): void
    {
        $admin = $this->createAdmin();

        $this->actingAs($admin)
            ->patch(route('admin.users.disable', $admin))
            ->assertForbidden();

        $this->assertTrue($admin->fresh()->is_active);
    }

    public function test_a_disabled_admin_session_is_revoked_before_authorization(): void
    {
        $admin = $this->createAdmin();
        $admin->is_active = false;
        $admin->disabled_at = now();
        $admin->save();

        $this->actingAs($admin)
            ->get('/admin/users')
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    private function createAdmin(): User
    {
        return User::factory()->create([
            'is_admin' => true,
            'is_active' => true,
        ]);
    }
}
