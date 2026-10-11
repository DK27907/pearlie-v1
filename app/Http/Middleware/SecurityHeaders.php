<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Symfony\Component\HttpFoundation\Response;

class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        Vite::useCspNonce();
        $response = $next($request);

        // Add common security headers
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->set('Permissions-Policy', 'camera=(), geolocation=(), microphone=()');
        $response->headers->set('X-XSS-Protection', '1; mode=block');

        // Enforce HSTS in production
        if (app()->environment('production')) {
            $response->headers->set('Strict-Transport-Security', 'max-age=63072000; includeSubDomains; preload');
        }

        $imageSources = $request->routeIs('home') ? ' https://media.gettyimages.com' : '';
        $viteHosts = app()->environment(['local', 'testing'])
            ? ' * ws: wss:'
            : '';
        $csp = "default-src 'self'; script-src 'self' 'nonce-".Vite::cspNonce()."'{$viteHosts}; style-src 'self' 'unsafe-inline'{$viteHosts}; connect-src 'self'{$viteHosts}; img-src 'self' data:".$imageSources.'; worker-src \'self\' blob:;';
        $response->headers->set('Content-Security-Policy', $csp);

        return $response;
    }
}
