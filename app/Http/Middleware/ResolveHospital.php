<?php

namespace App\Http\Middleware;

use App\Models\Hospital;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class ResolveHospital
{
    public function handle(Request $request, Closure $next): Response
    {
        $isHomeRoute = $request->routeIs('home');

        if ($request->is('api/v1/*')) {
            app()->instance('currentHospital', null);

            return $next($request);
        }

        if ($isHomeRoute || $request->routeIs('superadmin.*')) {
            app()->instance('currentHospital', null);

            if ($request->routeIs('superadmin.*')) {
                $this->authenticatedUser($request);
            }

            return $next($request);
        }

        $user = $this->authenticatedUser($request);
        $hospital = null;

        $impersonating = false;
        if ($user?->isSuperAdmin() && $request->hasSession()) {
            $impersonatedId = $request->session()->get('impersonating_hospital_id');
            if ($impersonatedId) {
                $hospital = Hospital::query()->find($impersonatedId);
                abort_unless($hospital, 404);
                $impersonating = true;
            }
        }

        if (! $hospital && $user?->isSuperAdmin()) {
            app()->instance('currentHospital', null);

            return $next($request);
        }

        $slug = $impersonating ? null : $this->tenantSlugFromRequest($request);
        if ($slug) {
            $hospital = Hospital::query()->where('slug', $slug)->first();
            abort_unless($hospital, 404, 'Hospital not found.');
        }

        if (! $hospital && $user && ! $user->isSuperAdmin() && $user->hospital_id) {
            $hospital = Hospital::query()->find($user->hospital_id);
        }

        if ($user && ! $user->isSuperAdmin()) {
            if (! $user->hospital_id) {
                abort(403, 'Your account is not assigned to a hospital.');
            }

            if ($hospital && (int) $hospital->id !== (int) $user->hospital_id) {
                abort(404);
            }

            $hospital ??= Hospital::query()->find($user->hospital_id);
        }

        if (! $hospital && ! $isHomeRoute) {
            $hospital = Hospital::query()
                ->where('slug', config('pearlie.default_hospital_slug', 'pearl'))
                ->first();
        }

        abort_unless($hospital, 404, 'No hospital is configured.');
        if (! $this->isAuthenticationRoute($request) && ! $this->isPaymentCallback($request)) {
            abort_unless(
                $hospital->is_active && in_array($hospital->subscription_status, ['active', 'trial'], true),
                403,
                'This hospital account is currently unavailable.',
            );
            abort_if(
                $hospital->subscription_status === 'trial'
                    && $hospital->trial_ends_at?->isPast(),
                403,
                'This hospital trial has expired.',
            );
        }

        app()->instance('currentHospital', $hospital);

        return $next($request);
    }

    private function tenantSlugFromRequest(Request $request): ?string
    {
        $path = $request->path();
        if (preg_match('#^(?:h|chat)/([a-z0-9-]+)(?:/|$)#i', $path, $matches)
            || preg_match('#^api/(?:whatsapp/webhook|mpesa/callback|payments/confirm)/([a-z0-9-]+)(?:/|$)#i', $path, $matches)
            || preg_match('#^hospital/([a-z0-9-]+)/invitation/#i', $path, $matches)) {
            return Str::lower($matches[1]);
        }

        if ($request->query('hospital')) {
            return Str::lower($request->string('hospital')->toString());
        }

        $baseDomain = Str::lower((string) config('tenancy.base_domain'));
        $host = Str::lower($request->getHost());
        if ($baseDomain !== '' && str_ends_with($host, '.'.$baseDomain)) {
            $subdomain = substr($host, 0, -strlen('.'.$baseDomain));

            return str_contains($subdomain, '.') ? null : $subdomain;
        }

        if (count(explode('.', $host)) < 3 || $baseDomain === '' || ! str_ends_with($host, '.'.$baseDomain)) {
            return null;
        }

        return null;
    }

    private function authenticatedUser(Request $request): ?User
    {
        $user = $request->user();
        if ($user instanceof User) {
            return $user;
        }

        if ($user !== null || ! $request->hasSession()) {
            return null;
        }

        $guard = Auth::guard('web');
        $userId = $request->session()->get($guard->getName());
        if (! is_int($userId) && (! is_string($userId) || ! ctype_digit($userId))) {
            return null;
        }

        // Session identity must be restored before host-based tenant context is available.
        $user = User::withoutGlobalScope('hospital')->find($userId);
        if ($user) {
            $guard->setUser($user);
        }

        return $user;
    }

    private function isAuthenticationRoute(Request $request): bool
    {
        return $request->routeIs(
            'login',
            'tenant.login',
            'tenant.login.store',
            'password.request',
            'password.email',
            'tenant.password.request',
            'tenant.password.email',
            'password.reset',
            'password.store',
            'register',
        );
    }

    private function isPaymentCallback(Request $request): bool
    {
        return $request->is('api/mpesa/callback')
            || $request->is('api/mpesa/callback/*')
            || $request->is('api/payments/confirm/*')
            || $request->is('mpesa/callback');
    }
}
