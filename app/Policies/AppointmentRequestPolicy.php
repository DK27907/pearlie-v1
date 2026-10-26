<?php

namespace App\Policies;

use App\Models\AppointmentRequest;
use App\Models\User;

class AppointmentRequestPolicy
{
    public function view(User $user, AppointmentRequest $appointmentRequest): bool
    {
        return $user->is_active
            && $user->isAdmin()
            && $user->hospital_id !== null
            && (int) $user->hospital_id === (int) $appointmentRequest->hospital_id;
    }
}
