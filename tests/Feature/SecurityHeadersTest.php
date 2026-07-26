<?php

namespace Tests\Feature;

use Tests\TestCase;

class SecurityHeadersTest extends TestCase
{
    public function test_browser_security_headers_are_applied_to_public_pages(): void
    {
        $response = $this->get(route('login'));

        $response->assertOk()
            ->assertHeader('Cross-Origin-Opener-Policy', 'same-origin')
            ->assertHeader('Cross-Origin-Resource-Policy', 'same-origin')
            ->assertHeader('Referrer-Policy', 'no-referrer')
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('X-Frame-Options', 'DENY');

        $policy = (string) $response->headers->get('Content-Security-Policy');
        $this->assertStringContainsString("frame-ancestors 'none'", $policy);
        $this->assertStringContainsString("object-src 'none'", $policy);
    }

    public function test_hsts_is_only_added_to_https_responses(): void
    {
        $this->get(route('login'))
            ->assertHeaderMissing('Strict-Transport-Security');

        $this->withServerVariables(['REMOTE_ADDR' => '172.20.0.10'])
            ->withHeader('X-Forwarded-Proto', 'https')
            ->get('/login')
            ->assertHeader('Strict-Transport-Security', 'max-age=31536000');
    }

    public function test_security_headers_are_applied_to_exception_responses(): void
    {
        $this->get('/path-that-does-not-exist')
            ->assertNotFound()
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('X-Frame-Options', 'DENY');
    }
}
