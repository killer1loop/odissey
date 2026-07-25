<?php

namespace Tests\Feature;

use Tests\TestCase;

class FoundationPageTest extends TestCase
{
    public function test_the_foundation_page_is_server_rendered(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee('Odissey')
            ->assertSee('IPTV first')
            ->assertSee('hx-boost="true"', escape: false);
    }

    public function test_htmx_can_refresh_the_foundation_status_fragment(): void
    {
        $this->withHeader('HX-Request', 'true')
            ->get('/foundation-status')
            ->assertOk()
            ->assertSee('Blade + HTMX')
            ->assertSee('FFmpeg planned');
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
