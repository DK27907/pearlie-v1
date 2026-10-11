<?php

namespace App\Support;

use App\Models\AppointmentRequest;

class CancellationResult
{
    public function __construct(
        public readonly AppointmentRequest $appointment,
        public readonly float $forfeitedAmount,
        public readonly string $paymentOutcome,
    ) {}
}
