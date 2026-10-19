<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

class PasswordController extends Controller
{
    public function edit(): View
    {
        return view('auth.password-edit');
    }

    /**
     * Update the user's password.
     */
    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validateWithBag('updatePassword', [
            'current_password' => ['required', 'current_password'],
            'password' => ['required', Password::defaults(), 'confirmed'],
        ]);

        $request->user()->update([
            'password' => Hash::make($validated['password']),
        ]);

        return back()->with('status', 'password-updated');
    }

    public function editForced(Request $request): View
    {
        $user = $request->user();
        abort_unless(
            $user
                && ($user->isDoctor() || ($user->isAdmin() && ! $user->isSuperAdmin()))
                && $user->must_change_password,
            404,
        );

        return view('auth.password-force-change');
    }

    public function updateForced(Request $request): RedirectResponse
    {
        $user = $request->user();
        abort_unless(
            $user
                && ($user->isDoctor() || ($user->isAdmin() && ! $user->isSuperAdmin()))
                && $user->must_change_password,
            404,
        );

        $validated = $request->validate([
            'password' => ['required', Password::defaults(), 'confirmed'],
        ]);

        $request->user()->forceFill([
            'password' => Hash::make($validated['password']),
            'must_change_password' => false,
        ])->save();

        $destination = $user->isDoctor() ? 'doctor.dashboard' : 'admin.dashboard';

        return redirect()->route($destination)->with('status', 'Your password has been changed.');
    }
}
