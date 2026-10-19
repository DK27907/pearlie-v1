<?php

namespace App\Services;

use App\Models\Hospital;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;

class HospitalManagementService
{
    public function __construct(private readonly HospitalInvitationService $invitations) {}

    /**
     * @param  array<string, mixed>  $attributes
     * @return array{hospital: Hospital, invitation_sent: bool}
     */
    public function create(array $attributes, int $inviterId): array
    {
        $adminEmail = (string) $attributes['admin_email'];

        [$hospital, $invite] = DB::transaction(function () use ($attributes, $adminEmail, $inviterId): array {
            $trialDays = (int) ($attributes['trial_days'] ?? 14);
            unset($attributes['trial_days'], $attributes['admin_email']);

            $hospital = Hospital::query()->create([
                ...$attributes,
                'subscription_status' => 'trial',
                'trial_ends_at' => now()->addDays($trialDays),
                'is_active' => true,
            ]);

            $hadCurrentHospital = app()->bound('currentHospital');
            $previousHospital = $hadCurrentHospital ? hospital() : null;
            app()->instance('currentHospital', $hospital);

            try {
                $invite = $this->invitations->create($hospital, $adminEmail, $inviterId);
            } finally {
                if ($hadCurrentHospital) {
                    app()->instance('currentHospital', $previousHospital);
                } else {
                    app()->forgetInstance('currentHospital');
                }
            }

            return [$hospital, $invite];
        });

        try {
            $this->invitations->deliver($hospital, $invite);
        } catch (TransportExceptionInterface) {
            return ['hospital' => $hospital, 'invitation_sent' => false];
        }

        return ['hospital' => $hospital, 'invitation_sent' => true];
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function createWithAdmin(array $attributes): Hospital
    {
        return DB::transaction(function () use ($attributes): Hospital {
            $trialDays = (int) ($attributes['trial_days'] ?? 14);
            $adminName = (string) $attributes['admin_name'];
            $adminEmail = (string) $attributes['admin_email'];
            $adminPassword = (string) $attributes['admin_password'];
            unset(
                $attributes['trial_days'],
                $attributes['admin_name'],
                $attributes['admin_email'],
                $attributes['admin_password'],
                $attributes['admin_password_confirmation'],
            );

            $hospital = Hospital::query()->create([
                ...$attributes,
                'subscription_status' => 'trial',
                'trial_ends_at' => now()->addDays($trialDays),
                'is_active' => true,
            ]);

            $admin = User::query()->create([
                'name' => $adminName,
                'email' => $adminEmail,
                'password' => Hash::make($adminPassword),
                'hospital_id' => $hospital->id,
                'role' => 'hospital_admin',
                'is_admin' => true,
                'is_doctor' => false,
                'is_super_admin' => false,
                'is_active' => true,
                'email_verified_at' => now(),
                'must_change_password' => true,
            ]);
            $admin->syncRolesFromFlags();

            return $hospital;
        });
    }
}
