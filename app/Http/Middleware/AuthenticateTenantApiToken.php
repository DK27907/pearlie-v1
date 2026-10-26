<?php

namespace App\Http\Middleware;

use App\Models\Hospital;
use App\Models\TenantApiToken;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateTenantApiToken
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $plainTextToken = $request->bearerToken();
        if (! is_string($plainTextToken)
            || preg_match('/^pvl_[a-f0-9]{64}$/', $plainTextToken) !== 1
        ) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $tokenHash = hash('sha256', $plainTextToken);
        $apiToken = TenantApiToken::withoutGlobalScopes()
            ->where('token_hash', $tokenHash)
            ->whereNull('revoked_at')
            ->first();
        if ($apiToken === null || ! hash_equals($apiToken->token_hash, $tokenHash)) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $hospital = Hospital::query()->find($apiToken->hospital_id);
        if (! $hospital
            || ! $hospital->is_active
            || ! in_array($hospital->subscription_status, ['active', 'trial'], true)
            || ($hospital->subscription_status === 'trial' && $hospital->trial_ends_at?->isPast())
        ) {
            return response()->json(['message' => 'This hospital account is unavailable.'], 403);
        }

        TenantApiToken::withoutGlobalScopes()
            ->whereKey($apiToken->id)
            ->whereNull('revoked_at')
            ->update(['last_used_at' => now()]);

        $hadCurrentHospital = app()->bound('currentHospital');
        $previousHospital = $hadCurrentHospital ? app('currentHospital') : null;
        app()->instance('currentHospital', $hospital);

        try {
            return $next($request);
        } finally {
            if ($hadCurrentHospital) {
                app()->instance('currentHospital', $previousHospital);
            } else {
                app()->forgetInstance('currentHospital');
            }
        }
    }
}
