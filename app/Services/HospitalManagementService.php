<?php

namespace App\Services;

use App\Models\Hospital;
use Illuminate\Support\Facades\DB;
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
}
