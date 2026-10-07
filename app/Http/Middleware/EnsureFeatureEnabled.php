<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureFeatureEnabled
{
    public function handle(Request $request, Closure $next, string $feature): Response
    {
        $hospital = hospital();
        abort_unless($hospital && $hospital->hasFeature($feature), 403, 'This feature is not included in your hospital plan.');

        return $next($request);
    }
}
