<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class NativeApiHeaders
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $this->prepare($request);

        return $this->apply($request, $next($request));
    }

    public function prepare(Request $request): void
    {
        if ($request->attributes->has('requestId')) {
            return;
        }

        $provided = trim((string) $request->header('X-Request-ID'));
        $request->attributes->set(
            'requestId',
            (
                $provided !== ''
                && strlen($provided) <= 64
                && preg_match('/^[A-Za-z0-9._:-]+$/', $provided) === 1
            ) ? $provided : (string) Str::uuid(),
        );
    }

    public function apply(Request $request, Response $response): Response
    {
        $this->prepare($request);
        $requestId = (string) $request->attributes->get('requestId');
        $response->headers->set('X-Request-ID', $requestId);

        if ($response instanceof JsonResponse) {
            $response->headers->set('Vary', 'Authorization', false);

            if (
                $request->isMethod('GET')
                && $response->isSuccessful()
                && ! str_contains(
                    (string) $response->headers->get('Cache-Control'),
                    'no-store',
                )
            ) {
                $etag = '"'.hash('sha256', (string) $response->getContent()).'"';
                $response->headers->set('ETag', $etag);

                if (hash_equals(
                    $etag,
                    trim((string) $request->header('If-None-Match')),
                )) {
                    $response->setStatusCode(304);
                    $response->setContent(null);
                }
            }
        }

        return $response;
    }
}
