<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FoundationPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_first_launch_routes_to_admin_setup(): void
    {
        $this->get('/')
            ->assertRedirect(route('setup.create'));
    }

    public function test_an_installed_guest_is_routed_to_login(): void
    {
        User::factory()->create(['is_active' => true]);

        $this->get('/')
            ->assertRedirect(route('login'));
    }

    public function test_the_authenticated_home_page_is_server_rendered(): void
    {
        $user = User::factory()->create(['is_active' => true]);

        $this->actingAs($user)
            ->get('/')
            ->assertOk()
            ->assertSee('Odissey')
            ->assertSee('Choose what to watch')
            ->assertSee(route('media.index'))
            ->assertSee(route('iptv.channels.index'))
            ->assertSee('hx-boost="true"', escape: false);
    }

    public function test_htmx_can_refresh_the_foundation_status_fragment(): void
    {
        $user = User::factory()->create(['is_active' => true]);

        $this->actingAs($user)
            ->withHeader('HX-Request', 'true')
            ->get('/foundation-status')
            ->assertOk()
            ->assertSee('Blade + HTMX 2')
            ->assertSee('Direct + FFmpeg HLS');
    }

    public function test_the_health_endpoint_is_available(): void
    {
        $this->get('/up')->assertOk();
    }

    public function test_the_default_local_upload_route_is_disabled(): void
    {
        $this->put('/storage/should-not-exist')->assertNotFound();
    }
}
