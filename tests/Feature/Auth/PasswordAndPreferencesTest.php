<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

class PasswordAndPreferencesTest extends TestCase
{
    use RefreshDatabase;

    public function test_reset_request_does_not_enumerate_accounts(): void
    {
        Notification::fake();
        $user = User::factory()->create();
        $this->post(route('password.email'), ['email' => $user->email])->assertSessionHas('status');
        $this->post(route('password.email'), ['email' => 'missing@example.test'])->assertSessionHas('status');
        Notification::assertSentTo($user, ResetPassword::class);
    }

    public function test_reset_link_always_uses_the_canonical_application_url(): void
    {
        Notification::fake();
        config(['app.url' => 'https://odissey.garavelli.io']);
        $user = User::factory()->create();

        $this->post('http://attacker.example.test/forgot-password', [
            'email' => $user->email,
        ])
            ->assertSessionHas('status');

        Notification::assertSentTo(
            $user,
            ResetPassword::class,
            function (ResetPassword $notification) use ($user): bool {
                $url = (string) $notification->toMail($user)->actionUrl;

                $this->assertStringStartsWith(
                    'https://odissey.garavelli.io/reset-password/',
                    $url,
                );
                $this->assertStringNotContainsString('attacker.example.test', $url);

                return true;
            },
        );
    }

    public function test_password_reset_uses_the_creation_strength_policy(): void
    {
        $user = User::factory()->create([
            'password' => 'VeryStrong!123',
        ]);
        $token = Password::createToken($user);

        $this->post(route('password.update'), [
            'token' => $token,
            'email' => $user->email,
            'password' => 'Short1!a',
            'password_confirmation' => 'Short1!a',
        ])->assertSessionHasErrors('password');

        $this->assertTrue(Hash::check('VeryStrong!123', $user->fresh()->password));
    }

    public function test_password_reset_revokes_every_database_session_for_the_user(): void
    {
        $user = User::factory()->create([
            'password' => 'VeryStrong!123',
            'remember_token' => 'old-remember-token',
        ]);
        $token = Password::createToken($user);

        foreach (['stolen-session', 'second-session'] as $sessionId) {
            DB::table('sessions')->insert([
                'id' => $sessionId,
                'user_id' => $user->getKey(),
                'ip_address' => '198.51.100.10',
                'user_agent' => 'Security regression test',
                'payload' => '',
                'last_activity' => now()->timestamp,
            ]);
        }

        config(['session.driver' => 'database']);

        $this->post(route('password.update'), [
            'token' => $token,
            'email' => $user->email,
            'password' => 'Different!1234',
            'password_confirmation' => 'Different!1234',
        ])->assertRedirect(route('login'));

        $this->assertDatabaseMissing('sessions', [
            'user_id' => $user->getKey(),
        ]);
        $this->assertTrue(
            Hash::check('Different!1234', $user->fresh()->password),
        );
        $this->assertNotSame(
            'old-remember-token',
            $user->fresh()->remember_token,
        );
    }

    public function test_user_can_save_timezone_and_playback_preferences(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->put(route('preferences.update'), [
            'timezone' => 'Europe/Zurich', 'preferred_quality' => '720p', 'autoplay' => '1',
        ])->assertRedirect();
        $this->assertSame('Europe/Zurich', $user->fresh()->timezone);
        $this->assertSame(['autoplay' => true, 'preferred_quality' => '720p'], $user->fresh()->preferences);
    }
}
