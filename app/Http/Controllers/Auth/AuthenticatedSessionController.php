<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class AuthenticatedSessionController extends Controller
{
    /**
     * Display the login view.
     */
    public function create(): View
    {
        return view('auth.login');
    }

    /**
     * Handle an incoming authentication request.
     */
    public function store(LoginRequest $request): RedirectResponse
    {
        $request->authenticate();

        $user = $request->user();
        $request->session()->regenerate();

        if ($user->must_change_password) {
            return redirect()->route('password.force.edit');
        }

        $defaultDestination = match (true) {
            $user->isSuperAdmin() => route('superadmin.dashboard'),
            $user->isDoctor() => route('doctor.dashboard'),
            $user->isAdmin() => route('admin.dashboard'),
            default => route('dashboard', absolute: false),
        };
        $intendedDestination = $this->intendedDestinationFor($request, $user);

        return redirect()->to($intendedDestination ?? $defaultDestination);
    }

    private function intendedDestinationFor(Request $request, User $user): ?string
    {
        $intendedUrl = $request->session()->pull('url.intended');

        if (! is_string($intendedUrl)) {
            return null;
        }

        $path = parse_url($intendedUrl, PHP_URL_PATH);

        if (! is_string($path)) {
            return null;
        }

        $allowedPrefixes = match (true) {
            $user->isSuperAdmin() => ['/superadmin'],
            $user->isDoctor() => ['/doctor'],
            $user->isAdmin() => ['/admin', '/hospital/onboarding'],
            default => ['/dashboard', '/appointments', '/profile', '/settings/profile'],
        };

        foreach ($allowedPrefixes as $prefix) {
            if ($path === $prefix || str_starts_with($path, $prefix.'/')) {
                $query = parse_url($intendedUrl, PHP_URL_QUERY);

                return $path.(is_string($query) && $query !== '' ? '?'.$query : '');
            }
        }

        return null;
    }

    /**
     * Destroy an authenticated session.
     */
    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();

        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
