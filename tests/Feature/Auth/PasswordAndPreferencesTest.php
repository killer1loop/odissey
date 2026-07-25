<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class PasswordAndPreferencesTest extends TestCase
{
    use RefreshDatabase;

    public function test_reset_request_does_not_enumerate_accounts(): void
    {
        Notification::fake();
        $user = User::factory()->create();
        $this->post(route('password.email'), ['email' => $user->email])->assertSessionHas('status');
        $this->post(route('password.email'), ['email' => 'missing@example.test'])->assertSessionHas('status');
        Notification::assertSentTo($user, ResetPassword::class);
    }

    public function test_user_can_save_timezone_and_playback_preferences(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->put(route('preferences.update'), [
            'timezone' => 'Europe/Zurich', 'preferred_quality' => '720p', 'autoplay' => '1',
        ])->assertRedirect();
        $this->assertSame('Europe/Zurich', $user->fresh()->timezone);
        $this->assertSame(['autoplay' => true, 'preferred_quality' => '720p'], $user->fresh()->preferences);
    }
}
