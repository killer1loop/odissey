<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class SetupTest extends TestCase
{
    use RefreshDatabase;

    public function test_setup_is_available_before_the_first_user_exists(): void
    {
        $this->get('/setup')
            ->assertOk()
            ->assertSee('Create the first administrator');
    }

    public function test_setup_atomically_claims_the_installation_and_creates_one_admin(): void
    {
        $response = $this->post('/setup', $this->validSetupPayload());

        $admin = User::sole();

        $response->assertRedirect(route('home'));
        $this->assertAuthenticatedAs($admin);
        $this->assertTrue($admin->is_admin);
        $this->assertTrue($admin->is_active);
        $this->assertTrue(Hash::check('VeryStrong!123', $admin->password));
        $this->assertNotNull($admin->email_verified_at);
        $this->assertDatabaseHas('installation_states', [
            'key' => 'initial_setup',
        ]);
        $this->assertNotNull(
            DB::table('installation_states')
                ->where('key', 'initial_setup')
                ->value('completed_at')
        );

        $this->post('/logout');
        $this->get('/setup')->assertNotFound();
        $this->post('/setup', [
            ...$this->validSetupPayload(),
            'email' => 'second@example.test',
        ])->assertNotFound();

        $this->assertSame(1, User::query()->where('is_admin', true)->count());
    }

    public function test_failed_validation_does_not_claim_the_installation(): void
    {
        $this->post('/setup', [
            ...$this->validSetupPayload(),
            'password_confirmation' => 'different',
        ])->assertSessionHasErrors('password');

        $this->assertNull(
            DB::table('installation_states')
                ->where('key', 'initial_setup')
                ->value('completed_at')
        );

        $this->post('/setup', $this->validSetupPayload())->assertRedirect(route('home'));
        $this->assertDatabaseCount('users', 1);
    }

    public function test_setup_requires_the_configured_token_in_production(): void
    {
        $this->withoutMiddleware(PreventRequestForgery::class);
        $this->app['env'] = 'production';
        config(['odissey-auth.setup_token' => 'server-only-token']);

        $this->get('/setup')
            ->assertOk()
            ->assertSee('Server setup token');

        $this->post('/setup', $this->validSetupPayload())
            ->assertSessionHasErrors('setup_token');
        $this->assertDatabaseCount('users', 0);

        $this->post('/setup', [
            ...$this->validSetupPayload(),
            'setup_token' => 'wrong-token',
        ])
            ->assertSessionHasErrors('setup_token')
            ->assertSessionMissing('_old_input.setup_token')
            ->assertSessionMissing('_old_input.password')
            ->assertSessionMissing('_old_input.password_confirmation');
        $this->assertDatabaseCount('users', 0);

        $this->post('/setup', [
            ...$this->validSetupPayload(),
            'setup_token' => 'server-only-token',
        ])->assertRedirect(route('home'));
        $this->assertDatabaseCount('users', 1);

        $this->app['env'] = 'testing';
    }

    public function test_setup_fails_closed_when_the_production_token_is_missing(): void
    {
        $this->withoutMiddleware(PreventRequestForgery::class);
        $this->app['env'] = 'production';
        config(['odissey-auth.setup_token' => null]);

        $this->get('/setup')
            ->assertOk()
            ->assertSee('Server setup token');

        $this->post('/setup', [
            ...$this->validSetupPayload(),
            'setup_token' => 'attacker-supplied-token',
        ])->assertSessionHasErrors('setup_token');

        $this->assertDatabaseCount('users', 0);
        $this->app['env'] = 'testing';
    }

    public function test_a_public_client_cannot_rotate_forwarded_ips_to_bypass_setup_throttling(): void
    {
        config(['trustedproxy.proxies' => ['172.20.0.0/16']]);

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.10'])
                ->withHeader('X-Forwarded-For', "198.51.100.{$attempt}")
                ->post('/setup', [])
                ->assertSessionHasErrors(['name', 'email', 'password']);
        }

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.10'])
            ->withHeader('X-Forwarded-For', '192.0.2.250')
            ->post('/setup', [])
            ->assertStatus(429);
    }

    public function test_a_trusted_proxy_uses_the_nearest_untrusted_forwarded_ip_for_setup_throttling(): void
    {
        config(['trustedproxy.proxies' => ['172.20.0.0/16']]);

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->withServerVariables(['REMOTE_ADDR' => '172.20.0.10'])
                ->withHeader(
                    'X-Forwarded-For',
                    "198.51.100.{$attempt}, 203.0.113.25",
                )
                ->post('/setup', [])
                ->assertSessionHasErrors(['name', 'email', 'password']);
        }

        $this->withServerVariables(['REMOTE_ADDR' => '172.20.0.10'])
            ->withHeader('X-Forwarded-For', '192.0.2.250, 203.0.113.25')
            ->post('/setup', [])
            ->assertStatus(429);
    }

    public function test_setup_is_unavailable_when_a_user_already_exists(): void
    {
        User::factory()->create();

        $this->get('/setup')->assertNotFound();
        $this->post('/setup', $this->validSetupPayload())->assertNotFound();
        $this->assertDatabaseCount('users', 1);
    }

    public function test_there_is_no_public_registration_route(): void
    {
        $this->get('/register')->assertNotFound();
        $this->post('/register', [])->assertNotFound();
    }

    /**
     * @return array<string, string>
     */
    private function validSetupPayload(): array
    {
        return [
            'name' => 'Odissey Admin',
            'email' => 'ADMIN@EXAMPLE.TEST',
            'password' => 'VeryStrong!123',
            'password_confirmation' => 'VeryStrong!123',
        ];
    }
}
