<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserIsHospitalAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $isImpersonating = $user?->isSuperAdmin()
            && $request->hasSession()
            && $request->session()->has('impersonating_hospital_id');
        abort_unless(
            $user && ($isImpersonating || (! $user->isSuperAdmin() && $user->isAdmin())),
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
