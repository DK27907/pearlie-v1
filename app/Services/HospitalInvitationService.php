<?php

namespace App\Services;

use App\Mail\HospitalAdminInviteMail;
use App\Models\Hospital;
use App\Models\Invite;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;
use Throwable;

class HospitalInvitationService
{
    public function send(Hospital $hospital, string $email, int $inviterId): Invite
    {
        $user = User::withoutGlobalScopes()->where('email', $email)->first();
        if ($user && (
            (int) $user->hospital_id !== (int) $hospital->id
            || $user->role !== 'hospital_admin'
        )) {
            throw ValidationException::withMessages([
                'email' => 'This email is already assigned to another account.',
            ]);
        }

        if (! $user) {
            $user = $hospital->users()->create([
                'name' => $email === $hospital->email ? 'Hospital administrator' : 'Hospital team member',
                'email' => $email,
                'password' => Hash::make(Str::random(64)),
                'role' => 'hospital_admin',
                'is_admin' => true,
            ]);
        }

        Role::firstOrCreate(['name' => 'hospital_admin', 'guard_name' => 'web']);
        if (! $user->hasRole('hospital_admin')) {
            $user->assignRole('hospital_admin');
        }

        $hospital->invites()
            ->where('email', $email)
            ->whereNull('used_at')
            ->update(['expires_at' => now()]);

        $invite = $hospital->invites()->create([
            'email' => $email,
            'token' => Str::random(64),
            'created_by' => $inviterId,
            'expires_at' => now()->addDays(7),
        ]);

        try {
            $url = URL::temporarySignedRoute(
                'hospital.invitation.setup',
                $invite->expires_at,
                ['slug' => $hospital->slug, 'token' => $invite->token],
            );
            Mail::to($invite->email)->send(new HospitalAdminInviteMail($hospital, $url));
        } catch (Throwable $exception) {
            Log::error('Unable to send a hospital administrator invitation.', [
                'hospital_id' => $hospital->id,
                'invite_id' => $invite->id,
                'exception' => $exception,
            ]);
            $invite->delete();

            throw $exception;
        }

        return $invite;
    }
}
