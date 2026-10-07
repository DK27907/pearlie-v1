<?php

namespace App\Services;

use App\Models\Hospital;
use Illuminate\Support\Facades\DB;

class HospitalManagementService
{
    public function __construct(private readonly HospitalInvitationService $invitations) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(array $attributes, int $inviterId): Hospital
    {
        $adminEmail = (string) $attributes['admin_email'];
        return DB::transaction(function () use ($attributes, $adminEmail, $inviterId): Hospital {
            $trialDays = (int) ($attributes['trial_days'] ?? 14);
            unset($attributes['trial_days'], $attributes['admin_email']);

            $hospital = Hospital::query()->create([
                ...$attributes,
                'subscription_status' => 'trial',
                'trial_ends_at' => now()->addDays($trialDays),
                'is_active' => true,
            ]);

            $this->invitations->send($hospital, $adminEmail, $inviterId);

            return $hospital;
        });
    }
}
