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

        $isImpersonating = $user->isSuperAdmin()
            && $request->hasSession()
            && $request->session()->has('impersonating_hospital_id');
        abort_unless(
            $isImpersonating || ($user->isAdmin() && ! $user->isSuperAdmin()),
            403,
            'Hospital administrator access required.',
        );
        abort_if(
            ! $isImpersonating
                && (! hospital() || (int) $user->hospital_id !== (int) hospital()->id),
            403,
            'You cannot access another hospital.',
        );

        return $next($request);
    }
}
