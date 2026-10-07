<?php

namespace App\Http\Controllers;

use App\Models\Invite;
use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Spatie\Permission\Models\Role;

class HospitalInvitationController extends Controller
{
    public function show(string $slug, string $token): View
    {
        $invite = $this->findInvite($slug, $token);
        $setupUrl = URL::temporarySignedRoute(
            'hospital.invitation.store',
            $invite->expires_at,
            ['slug' => $invite->hospital->slug, 'token' => $invite->token],
        );

        return view('hospital.invitation-setup', compact('invite', 'setupUrl'));
    }

    public function store(Request $request, string $slug, string $token): RedirectResponse
    {
        $invite = $this->findInvite($slug, $token);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'password' => ['required', 'confirmed', 'min:12', 'string'],
        ]);

        $user = DB::transaction(function () use ($invite, $data): User {
            $invite = Invite::query()
                ->whereKey($invite->id)
                ->where('hospital_id', $invite->hospital_id)
                ->valid()
                ->lockForUpdate()
                ->firstOrFail();

            $existingUser = User::withoutGlobalScopes()
                ->where('email', $invite->email)
                ->lockForUpdate()
                ->first();
            if ($existingUser && (
                (int) $existingUser->hospital_id !== (int) $invite->hospital_id
                || $existingUser->role !== 'hospital_admin'
            )) {
                throw ValidationException::withMessages([
                    'email' => 'This email already has an account. Ask a platform administrator to update its access.',
                ]);
            }

            $hospital = $invite->hospital;
            $user = $existingUser ?? $hospital->users()->make();
            $user->forceFill([
                'hospital_id' => $hospital->id,
                'name' => $data['name'],
                'email' => $invite->email,
                'password' => Hash::make($data['password']),
                'role' => 'hospital_admin',
                'is_admin' => true,
                'email_verified_at' => now(),
            ])->save();
            Role::firstOrCreate(['name' => 'hospital_admin', 'guard_name' => 'web']);
            $user->assignRole('hospital_admin');
            $invite->markUsed($user->id);

            return $user;
        });

        Auth::login($user);
        $request->session()->regenerate();
        event(new Registered($user));

        return redirect()->route('hospital.onboarding')->with('status', 'Welcome. Complete your hospital setup to launch MediDesk AI.');
    }

    private function findInvite(string $slug, string $token): Invite
    {
        return Invite::query()
            ->where('token', $token)
            ->whereHas('hospital', fn ($query) => $query->where('slug', $slug))
            ->valid()
            ->firstOrFail();
    }
}
