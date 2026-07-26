<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        return $this->apply($request, $next($request));
    }

    public function apply(Request $request, Response $response): Response
    {
        $headers = $response->headers;

        if (! $headers->has('Content-Security-Policy')) {
            $headers->set(
                'Content-Security-Policy',
                "default-src 'self'; base-uri 'self'; connect-src 'self'; font-src 'self' data:; "
                ."form-action 'self'; frame-ancestors 'none'; img-src 'self' data:; media-src 'self' blob:; "
                ."object-src 'none'; script-src 'self'; style-src 'self' 'unsafe-inline'",
            );
        }

        $headers->set('Cross-Origin-Opener-Policy', 'same-origin');
        $headers->set('Cross-Origin-Resource-Policy', 'same-origin');
        $headers->set('Permissions-Policy', 'camera=(), geolocation=(), microphone=(), payment=(), usb=()');
        $headers->set('Referrer-Policy', 'no-referrer');
        $headers->set('X-Content-Type-Options', 'nosniff');
        $headers->set('X-Frame-Options', 'DENY');

        if ($request->isSecure()) {
            $headers->set('Strict-Transport-Security', 'max-age=31536000');
        }

        return $response;
    }
}
