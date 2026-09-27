<?php

namespace App\Providers;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

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
        ResetPassword::createUrlUsing(function (User $user, string $token): string {
            return URL::route('password.reset', [
                'token' => $token,
                'email' => $user->getEmailForPasswordReset(),
                'hospital' => $user->hospital?->slug,
            ]);
        });

        // Register is_admin middleware alias so routes can use it
        $router = $this->app['router'] ?? null;
        if ($router) {
            $router->aliasMiddleware('is_admin', \App\Http\Middleware\EnsureUserIsAdmin::class);

            // Push security headers to the web middleware group so all web responses get security headers
            $router->pushMiddlewareToGroup('web', \App\Http\Middleware\SecurityHeaders::class);
        }
    }
}
