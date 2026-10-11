<?php

namespace App\Support;

use App\Models\AppointmentRequest;

class RescheduleResult
{
    public function __construct(public readonly AppointmentRequest $appointment) {}
}
