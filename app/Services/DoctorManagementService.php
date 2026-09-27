<?php

namespace App\Services;

use App\Mail\DoctorInviteMail;
use App\Models\DoctorInvite;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

class DoctorManagementService
{
    public function paginateDoctors(?string $search): LengthAwarePaginator
    {
        return User::query()
            ->where(function (Builder $query): void {
                $query->where('is_doctor', true)
                    ->orWhereHas('doctorInvites')
                    ->orWhereHas('doctorAppointments')
                    ->orWhereHas('availabilities');
            })
            ->when($search !== null && $search !== '', function (Builder $query) use ($search): void {
                $query->where(function (Builder $query) use ($search): void {
                    $query->where('name', 'like', '%'.$search.'%')
                        ->orWhere('email', 'like', '%'.$search.'%')
                        ->orWhere('specialization', 'like', '%'.$search.'%')
                        ->orWhere('phone', 'like', '%'.$search.'%');
                });
            })
            ->withCount('doctorInvites')
            ->orderBy('name')
            ->orderBy('id')
            ->paginate(15)
            ->withQueryString();
    }

    /**
     * @param  array{name: string, email: string, specialization: string, phone: string}  $attributes
     * @return array{doctor: User, invite_sent: bool}
     */
    public function createDoctor(array $attributes): array
    {
        [$doctor, $token, $invite] = DB::transaction(function () use ($attributes): array {
            $doctor = User::query()->create([
                ...$attributes,
                'password' => Str::random(64),
                'is_doctor' => true,
            ]);

            $token = Str::random(64);
            $invite = $doctor->doctorInvites()->create([
                'token' => hash('sha256', $token),
                'expires_at' => now()->addHours((int) config('doctor_invites.expiration_hours', 72)),
            ]);

            return [$doctor, $token, $invite];
        });

        return [
            'doctor' => $doctor,
            'invite_sent' => $this->sendInvite($doctor, $token, $invite->expires_at),
        ];
    }

    /**
     * @param  array{name: string, email: string, specialization: string, phone: string}  $attributes
     */
    public function updateDoctor(User $doctor, array $attributes): void
    {
        $doctor->update($attributes);
    }

    public function deactivateDoctor(User $doctor): void
    {
        DB::transaction(function () use ($doctor): void {
            $doctor->update(['is_doctor' => false]);

            $doctor->doctorInvites()
                ->whereNull('used_at')
                ->update(['used_at' => now()]);
        });
    }

    public function resendInvite(User $doctor): bool
    {
        abort_unless($doctor->isDoctor(), 404);

        [$token, $invite] = DB::transaction(function () use ($doctor): array {
            $doctor->doctorInvites()
                ->whereNull('used_at')
                ->update(['used_at' => now()]);

            $token = Str::random(64);
            $invite = $doctor->doctorInvites()->create([
                'token' => hash('sha256', $token),
                'expires_at' => now()->addHours((int) config('doctor_invites.expiration_hours', 72)),
            ]);

            return [$token, $invite];
        });

        return $this->sendInvite($doctor, $token, $invite->expires_at);
    }

    public function findValidInvite(string $token): DoctorInvite
    {
        return DoctorInvite::query()
            ->with('doctor')
            ->where('token', hash('sha256', $token))
            ->whereNull('used_at')
            ->where('expires_at', '>', now())
            ->whereHas('doctor', fn (Builder $query) => $query->where('is_doctor', true))
            ->firstOrFail();
    }

    public function completeSetup(string $token, string $password): User
    {
        $doctor = DB::transaction(function () use ($token, $password): User {
            $invite = DoctorInvite::query()
                ->where('token', hash('sha256', $token))
                ->whereNull('used_at')
                ->where('expires_at', '>', now())
                ->lockForUpdate()
                ->first();

            if (! $invite) {
                throw new NotFoundHttpException;
            }

            $doctor = User::query()
                ->whereKey($invite->doctor_id)
                ->where('is_doctor', true)
                ->lockForUpdate()
                ->first();

            if (! $doctor) {
                throw new NotFoundHttpException;
            }

            $doctor->forceFill(['password' => Hash::make($password)])->save();
            $invite->forceFill(['used_at' => now()])->save();

            $doctor->doctorInvites()
                ->whereNull('used_at')
                ->where('id', '!=', $invite->id)
                ->update(['used_at' => now()]);

            return $doctor;
        });

        Auth::login($doctor);

        return $doctor;
    }

    private function sendInvite(User $doctor, string $token, Carbon $expiresAt): bool
    {
        try {
            Mail::to($doctor->email)->send(new DoctorInviteMail($doctor, $token, $expiresAt));

            return true;
        } catch (Throwable $exception) {
            Log::error('Unable to send a doctor account invitation.', [
                'doctor_id' => $doctor->id,
                'exception' => $exception,
            ]);

            return false;
        }
    }
}
