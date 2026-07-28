<?php

namespace App\Http\Middleware;

use App\Http\ApiProblem;
use App\Services\Api\NativeTokenService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateNativeClient
{
    public function __construct(
        private readonly NativeTokenService $tokens,
    ) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken();
        if (! is_string($token) || $token === '') {
            return ApiProblem::authentication();
        }

        $session = $this->tokens->findForAccessToken($token);
        if ($session === null) {
            return ApiProblem::authentication(
                'The native-client access token is invalid or expired.',
            );
        }

        Auth::setUser($session->user);
        $request->setUserResolver(
            static fn () => $session->user,
        );
        $request->attributes->set('nativeClientSession', $session);

        return $next($request);
    }
}
