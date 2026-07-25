<?php

namespace Tests\Feature\Iptv;

use App\Jobs\Iptv\SyncIptvProvider;
use App\Models\Iptv\IptvProvider;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\InteractsWithIptv;
use Tests\TestCase;

class IptvProviderManagementTest extends TestCase
{
    use InteractsWithIptv;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->loadIptvRoutes();
        $this->allowPublicIptvDns();
    }

    public function test_only_an_admin_can_manage_providers(): void
    {
        $user = User::factory()->create(['is_active' => true, 'is_admin' => false]);
        $admin = User::factory()->create(['is_active' => true, 'is_admin' => true]);

        $this->actingAs($user)
            ->get(route('iptv.admin.providers.index'))
            ->assertForbidden();

        $this->actingAs($admin)
            ->get(route('iptv.admin.providers.index'))
            ->assertOk()
            ->assertSee('IPTV providers');
    }

    public function test_http_provider_requires_explicit_consent_and_secrets_stay_encrypted(): void
    {
        Queue::fake();
        $admin = User::factory()->create(['is_active' => true, 'is_admin' => true]);
        $secrets = [
            'base_url' => 'http://upstream.example.test',
            'username' => 'extremely-private-user',
            'password' => 'extremely-private-password',
        ];

        $this->actingAs($admin)
            ->post(route('iptv.admin.providers.store'), [
                'name' => 'Consent required',
                ...$secrets,
                'enabled' => '1',
            ])
            ->assertSessionHasErrors('allow_insecure_http');

        $this->assertDatabaseCount('iptv_providers', 0);

        $this->actingAs($admin)
            ->post(route('iptv.admin.providers.store'), [
                'name' => 'Encrypted provider',
                ...$secrets,
                'allow_insecure_http' => '1',
                'enabled' => '1',
            ])
            ->assertRedirect(route('iptv.admin.providers.index'));

        Queue::assertPushed(SyncIptvProvider::class);
        $provider = IptvProvider::query()->sole();
        $raw = DB::table('iptv_providers')->where('id', $provider->id)->first();

        $this->assertSame($secrets['base_url'], $provider->base_url);
        $this->assertSame($secrets['username'], $provider->username);
        $this->assertSame($secrets['password'], $provider->password);

        foreach ($secrets as $secret) {
            $this->assertStringNotContainsString($secret, (string) $raw->base_url);
            $this->assertStringNotContainsString($secret, (string) $raw->username);
            $this->assertStringNotContainsString($secret, (string) $raw->password);
        }

        $this->actingAs($admin)
            ->get(route('iptv.admin.providers.index'))
            ->assertOk()
            ->assertDontSee($secrets['base_url'])
            ->assertDontSee($secrets['username'])
            ->assertDontSee($secrets['password']);

        $this->actingAs($admin)
            ->get(route('iptv.admin.providers.edit', $provider))
            ->assertOk()
            ->assertDontSee($secrets['base_url'])
            ->assertDontSee($secrets['username'])
            ->assertDontSee($secrets['password']);
    }

    public function test_provider_validation_never_flashes_connection_details_to_old_input(): void
    {
        $admin = User::factory()->create(['is_active' => true, 'is_admin' => true]);

        $response = $this->actingAs($admin)
            ->from(route('iptv.admin.providers.create'))
            ->post(route('iptv.admin.providers.store'), [
                'name' => '',
                'base_url' => 'http://private-provider.example.test',
                'username' => 'never-flash-user',
                'password' => 'never-flash-password',
                'enabled' => '1',
            ])
            ->assertRedirect(route('iptv.admin.providers.create'))
            ->assertSessionHasErrors();

        $oldInput = $response->getSession()->getOldInput();

        $this->assertArrayNotHasKey('base_url', $oldInput);
        $this->assertArrayNotHasKey('username', $oldInput);
        $this->assertArrayNotHasKey('password', $oldInput);
    }
}
