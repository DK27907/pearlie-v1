<?php

namespace App\Services;

use App\Mail\HospitalAdminInviteMail;
use App\Models\Hospital;
use App\Models\Invite;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class HospitalInvitationService
{
    public function __construct(private readonly HospitalMailService $mail) {}

    public function send(Hospital $hospital, string $email, int $inviterId): Invite
    {
        $invite = $this->create($hospital, $email, $inviterId);
        $this->deliver($hospital, $invite);

        return $invite;
    }

    public function create(Hospital $hospital, string $email, int $inviterId): Invite
    {
        // Email is globally unique; inspect cross-tenant ownership before refusing account reuse.
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

        $user->forceFill([
            'is_admin' => true,
            'is_super_admin' => false,
            'is_doctor' => false,
        ])->save();
        $user->syncRolesFromFlags();

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

        return $invite;
    }

    public function deliver(Hospital $hospital, Invite $invite): void
    {
        try {
            $url = URL::temporarySignedRoute(
                'hospital.invitation.setup',
                $invite->expires_at,
                ['slug' => $hospital->slug, 'token' => $invite->token],
            );
            $this->mail->send($invite->email, new HospitalAdminInviteMail($hospital, $url));
        } catch (Throwable $exception) {
            Log::error('Unable to send a hospital administrator invitation.', [
                'hospital_id' => $hospital->id,
                'invite_id' => $invite->id,
                'exception' => $exception,
            ]);
            $invite->delete();

            throw $exception;
        }
    }
}
