<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_active_user_can_log_in_and_the_session_is_regenerated(): void
    {
        $user = User::factory()->create([
            'email' => 'viewer@example.test',
            'password' => 'VeryStrong!123',
        ]);

        $this->get('/login')->assertOk();
        $oldSessionId = $this->app['session.store']->getId();

        $this->post('/login', [
            'email' => 'VIEWER@EXAMPLE.TEST',
            'password' => 'VeryStrong!123',
        ])->assertRedirect(route('home'));

        $this->assertAuthenticatedAs($user);
        $this->assertNotSame($oldSessionId, $this->app['session.store']->getId());
    }

    public function test_invalid_logins_are_rate_limited(): void
    {
        User::factory()->create([
            'email' => 'viewer@example.test',
            'password' => 'VeryStrong!123',
        ]);

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->post('/login', [
                'email' => 'viewer@example.test',
                'password' => 'incorrect-password',
            ])->assertSessionHasErrors('email');
        }

        $key = 'viewer@example.test|127.0.0.1';
        $this->assertTrue(RateLimiter::tooManyAttempts($key, 5));

        $this->post('/login', [
            'email' => 'viewer@example.test',
            'password' => 'VeryStrong!123',
        ])->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_login_password_spraying_is_limited_across_email_addresses(): void
    {
        $user = User::factory()->create([
            'email' => 'viewer@example.test',
            'password' => 'VeryStrong!123',
        ]);

        for ($attempt = 1; $attempt <= 20; $attempt++) {
            $this->post('/login', [
                'email' => "spray-{$attempt}@example.test",
                'password' => 'incorrect-password',
            ])->assertSessionHasErrors('email');
        }

        $this->assertTrue(RateLimiter::tooManyAttempts(
            'login-ip|127.0.0.1',
            20,
        ));

        $this->post('/login', [
            'email' => $user->email,
            'password' => 'VeryStrong!123',
        ])->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_a_public_client_cannot_rotate_forwarded_ips_to_bypass_login_throttling(): void
    {
        config(['trustedproxy.proxies' => ['172.20.0.0/16']]);

        User::factory()->create([
            'email' => 'viewer@example.test',
            'password' => 'VeryStrong!123',
        ]);

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.10'])
                ->withHeader('X-Forwarded-For', "198.51.100.{$attempt}")
                ->post('/login', [
                    'email' => 'viewer@example.test',
                    'password' => 'incorrect-password',
                ])
                ->assertSessionHasErrors('email');
        }

        $this->assertTrue(RateLimiter::tooManyAttempts(
            'viewer@example.test|203.0.113.10',
            5,
        ));

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.10'])
            ->withHeader('X-Forwarded-For', '192.0.2.250')
            ->post('/login', [
                'email' => 'viewer@example.test',
                'password' => 'VeryStrong!123',
            ])
            ->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_a_trusted_proxy_uses_the_nearest_untrusted_forwarded_ip_for_login_throttling(): void
    {
        config(['trustedproxy.proxies' => ['172.20.0.0/16']]);

        User::factory()->create([
            'email' => 'viewer@example.test',
            'password' => 'VeryStrong!123',
        ]);

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->withServerVariables(['REMOTE_ADDR' => '172.20.0.10'])
                ->withHeader(
                    'X-Forwarded-For',
                    "198.51.100.{$attempt}, 203.0.113.25",
                )
                ->post('/login', [
                    'email' => 'viewer@example.test',
                    'password' => 'incorrect-password',
                ])
                ->assertSessionHasErrors('email');
        }

        $this->assertTrue(RateLimiter::tooManyAttempts(
            'viewer@example.test|203.0.113.25',
            5,
        ));

        $this->withServerVariables(['REMOTE_ADDR' => '172.20.0.10'])
            ->withHeader('X-Forwarded-For', '192.0.2.250, 203.0.113.25')
            ->post('/login', [
                'email' => 'viewer@example.test',
                'password' => 'VeryStrong!123',
            ])
            ->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_a_disabled_user_cannot_log_in(): void
    {
        User::factory()->create([
            'email' => 'disabled@example.test',
            'password' => 'VeryStrong!123',
            'is_active' => false,
            'disabled_at' => now(),
        ]);

        $this->post('/login', [
            'email' => 'disabled@example.test',
            'password' => 'VeryStrong!123',
        ])->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_logout_invalidates_the_authenticated_session(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $this->post('/logout')->assertRedirect(route('login'));

        $this->assertGuest();
    }

    public function test_a_password_change_invalidates_an_existing_authenticated_session(): void
    {
        $user = User::factory()->create([
            'password' => 'VeryStrong!123',
        ]);

        $this->actingAs($user)
            ->get(route('preferences.edit'))
            ->assertOk();
        $this->assertTrue(
            $this->app['session.store']->has('password_hash_web'),
        );

        User::query()
            ->whereKey($user->getKey())
            ->update(['password' => Hash::make('Different!Password123')]);
        $this->app['auth']->forgetGuards();

        $this->get(route('preferences.edit'))
            ->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_production_accepts_only_the_configured_exact_host(): void
    {
        $originalEnvironment = $this->app['env'];
        $this->app['env'] = 'production';
        config([
            'odissey-auth.trusted_hosts' => ['^odissey\\.garavelli\\.io$'],
        ]);

        try {
            $this->get('http://odissey.garavelli.io/login')
                ->assertOk();

            $this->get('http://attacker.example.test/login')
                ->assertBadRequest();
        } finally {
            $this->app['env'] = $originalEnvironment;
            Request::setTrustedHosts([]);
        }
    }
}
