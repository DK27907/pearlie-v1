<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class EnsureUserIsAdmin
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();

        if (! $user) {
            abort(403, 'Unauthorized - admin access required');
        }

        // Allow either is_admin flag or spatie role 'admin'. If roles table isn't present, treat as not having the role.
        $isAdminFlag = $user->is_admin ?? false;
        $hasAdminRole = false;

        try {
            $hasAdminRole = method_exists($user, 'hasRole') ? $user->hasRole('admin') : false;
        } catch (\Throwable $e) {
            // Roles table or spatie not available in this environment — assume not admin by role
            \Illuminate\Support\Facades\Log::info('EnsureUserIsAdmin: role check failed: ' . $e->getMessage());
            $hasAdminRole = false;
        }

        if (! $isAdminFlag && ! $hasAdminRole) {
            abort(403, 'Unauthorized - admin access required');
        }

        return $next($request);
    }
}
