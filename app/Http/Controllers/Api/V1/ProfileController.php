<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use DateTimeZone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ProfileController extends Controller
{
    public function me(Request $request): JsonResponse
    {
        return response()->json([
            'data' => $this->payload($request),
        ], headers: ['Cache-Control' => 'private, max-age=30']);
    }

    public function profiles(Request $request): JsonResponse
    {
        return response()->json([
            'data' => [[
                ...$this->payload($request),
                'active' => true,
            ]],
        ], headers: ['Cache-Control' => 'private, max-age=30']);
    }

    public function activate(
        Request $request,
        string $profile,
    ): JsonResponse {
        abort_unless(
            hash_equals((string) $request->user()->getKey(), $profile),
            404,
        );

        return response()->json([
            'data' => [
                ...$this->payload($request),
                'active' => true,
            ],
        ], headers: ['Cache-Control' => 'no-store']);
    }

    public function updatePreferences(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'timezone' => [
                'required',
                Rule::in(DateTimeZone::listIdentifiers()),
            ],
            'autoplay' => ['required', 'boolean'],
            'preferredQuality' => [
                'required',
                Rule::in(['auto', 'original', '1080p', '720p']),
            ],
        ]);
        $request->user()->update([
            'timezone' => $validated['timezone'],
            'preferences' => [
                'autoplay' => $validated['autoplay'],
                'preferred_quality' => $validated['preferredQuality'],
            ],
        ]);

        return response()->json([
            'data' => $this->payload($request),
        ], headers: ['Cache-Control' => 'no-store']);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(Request $request): array
    {
        $user = $request->user();

        return [
            'id' => (string) $user->getKey(),
            'name' => $user->name,
            'email' => $user->email,
            'timezone' => $user->timezone ?: 'UTC',
            'isAdmin' => $user->isAdmin(),
            'preferences' => [
                'timezone' => $user->timezone ?: 'UTC',
                'autoplay' => (bool) (
                    $user->preferences['autoplay'] ?? false
                ),
                'preferredQuality' => (string) (
                    $user->preferences['preferred_quality'] ?? 'auto'
                ),
            ],
        ];
    }
}
