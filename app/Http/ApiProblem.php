<?php

namespace App\Http;

use App\Http\Middleware\NativeApiHeaders;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;

class ApiProblem
{
    /**
     * @param  array<string, mixed>  $extra
     * @param  array<string, string>  $headers
     */
    public static function response(
        int $status,
        string $code,
        string $title,
        string $detail,
        array $extra = [],
        array $headers = [],
    ): JsonResponse {
        $request = request();
        app(NativeApiHeaders::class)->prepare($request);
        $requestId = (string) $request->attributes->get('requestId');

        return response()->json([
            'type' => 'https://odissey.app/problems/'.$code,
            'title' => $title,
            'status' => $status,
            'detail' => $detail,
            'code' => $code,
            'requestId' => $requestId,
            ...$extra,
        ], $status, [
            'Cache-Control' => 'no-store',
            'Content-Type' => 'application/problem+json',
            ...$headers,
        ]);
    }

    public static function authentication(
        string $detail = 'A valid native-client access token is required.',
    ): JsonResponse {
        return self::response(
            401,
            'authentication_required',
            'Authentication required',
            $detail,
            headers: ['WWW-Authenticate' => 'Bearer'],
        );
    }

    public static function forbidden(
        string $detail = 'This account cannot perform the requested action.',
    ): JsonResponse {
        return self::response(403, 'forbidden', 'Forbidden', $detail);
    }

    public static function notFound(): JsonResponse
    {
        return self::response(
            404,
            'not_found',
            'Not found',
            'The requested resource was not found.',
        );
    }

    public static function validation(
        ValidationException $exception,
    ): JsonResponse {
        return self::response(
            422,
            'validation_failed',
            'Validation failed',
            'One or more request fields are invalid.',
            ['errors' => Arr::map(
                $exception->errors(),
                static fn (array $messages): array => array_values($messages),
            )],
        );
    }
}
