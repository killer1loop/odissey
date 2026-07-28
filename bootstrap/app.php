<?php

use App\Http\ApiProblem;
use App\Http\Middleware\AuthenticateNativeClient;
use App\Http\Middleware\AuthenticateNativePlaybackGrant;
use App\Http\Middleware\EnsureNativeClientIsAdmin;
use App\Http\Middleware\NativeApiHeaders;
use App\Http\Middleware\SecurityHeaders;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'native.admin' => EnsureNativeClientIsAdmin::class,
        ]);
        $middleware->prependToPriorityList(
            SubstituteBindings::class,
            NativeApiHeaders::class,
        );
        $middleware->prependToPriorityList(
            SubstituteBindings::class,
            AuthenticateNativeClient::class,
        );
        $middleware->prependToPriorityList(
            SubstituteBindings::class,
            EnsureNativeClientIsAdmin::class,
        );
        $middleware->prependToPriorityList(
            SubstituteBindings::class,
            AuthenticateNativePlaybackGrant::class,
        );
        $middleware->append(SecurityHeaders::class);
        $middleware->authenticateSessions();
        $middleware->trustHosts(
            at: static fn (): array => config('odissey-auth.trusted_hosts', []),
            subdomains: false,
        );
        $middleware->trustProxies(
            headers: Request::HEADER_X_FORWARDED_FOR
                | Request::HEADER_X_FORWARDED_HOST
                | Request::HEADER_X_FORWARDED_PORT
                | Request::HEADER_X_FORWARDED_PROTO,
        );
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(function (
            ValidationException $exception,
            Request $request,
        ) {
            return $request->is('api/*')
                ? ApiProblem::validation($exception)
                : null;
        });
        $exceptions->render(function (
            AuthenticationException $exception,
            Request $request,
        ) {
            return $request->is('api/*')
                ? ApiProblem::authentication()
                : null;
        });
        $exceptions->render(function (
            AuthorizationException $exception,
            Request $request,
        ) {
            return $request->is('api/*')
                ? ApiProblem::forbidden()
                : null;
        });
        $exceptions->render(function (
            ModelNotFoundException $exception,
            Request $request,
        ) {
            return $request->is('api/*')
                ? ApiProblem::notFound()
                : null;
        });
        $exceptions->render(function (
            HttpExceptionInterface $exception,
            Request $request,
        ) {
            if (! $request->is('api/*')) {
                return null;
            }

            $status = $exception->getStatusCode();
            $code = match ($status) {
                403 => 'forbidden',
                404 => 'not_found',
                409 => 'conflict',
                410 => 'gone',
                429 => 'rate_limited',
                default => 'http_error',
            };
            $title = Response::$statusTexts[$status] ?? 'Request failed';
            $detail = in_array($status, [400, 409, 410, 422, 429], true)
                && trim($exception->getMessage()) !== ''
                ? $exception->getMessage()
                : $title;

            return ApiProblem::response(
                $status,
                $code,
                $title,
                $detail,
                headers: $exception->getHeaders(),
            );
        });

        $exceptions->respond(
            static fn (
                Response $response,
                Throwable $exception,
                Request $request,
            ): Response => $request->attributes->has('requestId')
                ? app(NativeApiHeaders::class)->apply(
                    $request,
                    app(SecurityHeaders::class)->apply($request, $response),
                )
                : app(SecurityHeaders::class)->apply($request, $response),
        );

        $exceptions->dontFlash([
            'access_key',
            'accessToken',
            'base_url',
            'current_password',
            'endpoint',
            'tmdb_api_token',
            'subdl_api_key',
            'opensubtitles_api_key',
            'password',
            'password_confirmation',
            'path',
            'playlist_url',
            'secret_key',
            'refreshToken',
            'setup_token',
            'setupToken',
            'url',
            'username',
            'xmltv_url',
        ]);

        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );
    })->create();
