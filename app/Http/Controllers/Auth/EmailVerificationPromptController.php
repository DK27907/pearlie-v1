<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class EmailVerificationPromptController extends Controller
{
    /**
     * Display the email verification prompt.
     */
    public function __invoke(Request $request): RedirectResponse|View
    {
        $user = $request->user();

        if (! $user->hasVerifiedEmail()) {
            return view('auth.verify-email');
        }

        $dashboard = match (true) {
            $user instanceof User && $user->isSuperAdmin() => route('superadmin.dashboard', absolute: false),
            $user instanceof User && $user->isDoctor() => route('doctor.dashboard', absolute: false),
            $user instanceof User && $user->isAdmin() => route('admin.dashboard', absolute: false),
            default => route('dashboard', absolute: false),
        };

        return redirect()->intended($dashboard);
    }
}
