<?php

namespace App\Providers;

use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Password::defaults(
            static fn (): Password => Password::min(12)
                ->letters()
                ->mixedCase()
                ->numbers()
                ->symbols(),
        );

        ResetPassword::createUrlUsing(static function ($notifiable, string $token): string {
            $path = route('password.reset', [
                'token' => $token,
                'email' => $notifiable->getEmailForPasswordReset(),
            ], absolute: false);

            return rtrim((string) config('app.url'), '/').$path;
        });
    }
}
