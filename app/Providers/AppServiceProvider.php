<?php

namespace App\Providers;

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
        // Register is_admin middleware alias so routes can use it
        $router = $this->app['router'] ?? null;
        if ($router) {
            $router->aliasMiddleware('is_admin', \App\Http\Middleware\EnsureUserIsAdmin::class);

            // Push security headers to the web middleware group so all web responses get security headers
            $router->pushMiddlewareToGroup('web', \App\Http\Middleware\SecurityHeaders::class);
        }
    }
}
