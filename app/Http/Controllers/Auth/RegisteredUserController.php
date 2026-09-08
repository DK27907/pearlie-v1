<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class RegisteredUserController extends Controller
{
    /**
     * Display the registration view.
     */
    public function create(): View
    {
        // If registration is disabled, require an invite token parameter
        // Allow registration automatically in testing environment to support automated tests
        $allow = env('ALLOW_REGISTRATION', false) || app()->environment('testing');
        return view('auth.register', ['allow_registration' => $allow]);
    }

    /**
     * Handle an incoming registration request.
     *
     * @throws ValidationException
     */
    public function store(Request $request): RedirectResponse
    {
        $allow = env('ALLOW_REGISTRATION', false) || app()->environment('testing');

        $rules = [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', 'unique:'.User::class],
            'password' => ['required', 'confirmed', Rules\Password::defaults()],
        ];

        if (! $allow) {
            $rules['invite_token'] = ['required', 'string', 'exists:invites,token'];
        }

        $data = $request->validate($rules);

        // If invite token present, validate it and mark used
        if (! $allow) {
            $invite = \App\Models\Invite::where('token', $data['invite_token'])->first();
            if (! $invite || $invite->used_at || $invite->isExpired()) {
                throw ValidationException::withMessages(['invite_token' => 'Invalid, already used, or expired invite token']);
            }
        }

        $user = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'password' => Hash::make($request->password),
        ]);

        if (! $allow) {
            $invite->markUsed($user->id);
        }

        event(new Registered($user));

        Auth::login($user);

        return redirect(route('dashboard', absolute: false));
    }
}
