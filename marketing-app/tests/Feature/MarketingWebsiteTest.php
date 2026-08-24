<?php

namespace Tests\Feature;

use App\Models\LaunchSignup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MarketingWebsiteTest extends TestCase
{
    use RefreshDatabase;

    public function test_homepage_renders_with_built_in_assets(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee('Every source.')
            ->assertSee('src="/favicon.svg"', false)
            ->assertSee('/vendor/htmx.min.js', false)
            ->assertSee('"allowEval":false', false);

        $this->assertFileExists(public_path('favicon.svg'));
        $this->assertFileExists(public_path('vendor/htmx.min.js'));
    }

    public function test_health_endpoint_is_available(): void
    {
        $this->get('/health')->assertOk()->assertSeeText('ok');
    }

    public function test_https_links_are_generated_behind_the_private_reverse_proxy(): void
    {
        $this->withServerVariables([
            'REMOTE_ADDR' => '10.0.0.10',
            'HTTP_X_FORWARDED_HOST' => 'odissey.app',
            'HTTP_X_FORWARDED_PORT' => '443',
            'HTTP_X_FORWARDED_PROTO' => 'https',
        ])->get('http://odissey.app/')
            ->assertOk()
            ->assertSee('hx-post="https://odissey.app/subscribe"', false);
    }

    public function test_container_build_context_keeps_runtime_configuration(): void
    {
        $ignored = file_get_contents(base_path('.dockerignore'));
        $dockerfile = file_get_contents(base_path('Dockerfile'));
        $caddyfile = file_get_contents(base_path('docker/Caddyfile'));

        $this->assertIsString($ignored);
        $this->assertIsString($dockerfile);
        $this->assertIsString($caddyfile);
        $this->assertDoesNotMatchRegularExpression('/^docker\/?$/m', $ignored);
        $this->assertStringContainsString('docker/entrypoint.sh /usr/local/bin/marketing-entrypoint', $dockerfile);
        $this->assertStringContainsString('COPY docker/Caddyfile', $dockerfile);
        $this->assertStringContainsString('composer.json ./', $dockerfile);
        $this->assertStringContainsString('pdo_sqlite', $dockerfile);
        $this->assertStringContainsString('APP_URL=https://odissey.app', $dockerfile);
        $this->assertStringContainsString('Content-Security-Policy', $caddyfile);
        $this->assertStringContainsString('trusted_proxies static private_ranges', $caddyfile);
    }

    public function test_launch_signup_is_normalized_and_persisted(): void
    {
        $this->post('/subscribe', ['email' => '  Viewer@Example.COM '])
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertSee('You are on the list.');

        $this->assertDatabaseHas('launch_signups', [
            'email' => 'viewer@example.com',
        ]);
    }

    public function test_duplicate_launch_signup_is_idempotent(): void
    {
        LaunchSignup::query()->create(['email' => 'viewer@example.com']);

        $this->post('/subscribe', ['email' => 'VIEWER@example.com'])
            ->assertOk()
            ->assertSee('You are on the list.');

        $this->assertSame(1, LaunchSignup::query()->count());
    }

    public function test_invalid_launch_signup_returns_the_form_with_an_error(): void
    {
        $this->post('/subscribe', ['email' => 'not-an-email'])
            ->assertUnprocessable()
            ->assertSee('valid email address')
            ->assertSee('aria-invalid="true"', false);

        $this->assertDatabaseEmpty('launch_signups');
    }
}
